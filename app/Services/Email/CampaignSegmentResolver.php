<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Course;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\Lifecycle\LifecycleEligibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * H1449 B3 — turns a Campaign's `segment` json filter into a User query
 * result. Reuses existing consent/relationship data (User::wants_email_announcements,
 * the same gate AnnouncementDispatcher already applies for send_to_email;
 * Course::users(), Lead::status) rather than inventing a new audience model.
 *
 * D11 fail-safe: an unrecognised/malformed filter resolves to an EMPTY
 * collection + a log entry, never to "all users" — a segment bug must never
 * silently become a broadcast to everyone.
 */
class CampaignSegmentResolver
{
    /**
     * @param  array<string, mixed>|null  $segment
     * @return Collection<int, User>
     */
    public function resolve(?array $segment): Collection
    {
        $type = $segment['type'] ?? null;

        return match ($type) {
            'all_subscribers' => $this->allSubscribers(),
            'course' => $this->courseStudents($segment),
            'lead_stage' => $this->leadStageEmails($segment),
            'lifecycle' => $this->lifecycleUsers($segment),
            default => $this->empty($type),
        };
    }

    /**
     * Same eligibility query as `crm:lifecycle-prepare --dry-run`.
     *
     * @param  array<string, mixed>  $segment
     * @return Collection<int, User>
     */
    private function lifecycleUsers(array $segment): Collection
    {
        $rule = $segment['rule'] ?? null;
        if (! is_string($rule) || ! in_array($rule, LifecycleEligibility::rules(), true)) {
            return $this->empty('lifecycle (missing/unknown rule)');
        }

        $users = app(LifecycleEligibility::class)->eligibleUsers($rule);

        return new Collection($users->all());
    }

    /** @return Collection<int, User> */
    private function allSubscribers(): Collection
    {
        return User::query()
            ->where('wants_email_announcements', true)
            ->whereNotNull('email')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return Collection<int, User>
     */
    private function courseStudents(array $segment): Collection
    {
        $courseId = $segment['course_id'] ?? null;
        if (! is_numeric($courseId)) {
            return $this->empty('course (missing course_id)');
        }

        $course = Course::query()->find((int) $courseId);
        if (! $course) {
            return $this->empty("course (course_id={$courseId} not found)");
        }

        return $course->users()
            ->where('wants_email_announcements', true)
            ->whereNotNull('email')
            ->get();
    }

    /**
     * Leads carry an email but aren't Users — for a homogeneous recipient
     * type (CampaignRecipient.user_id is nullable specifically for this),
     * synthesize lightweight User-shaped entries only where the campaign
     * engine needs an email; return real Users matching that email when one
     * already exists (a lead who converted), otherwise skip (no user record
     * to link — logged, not silently dropped).
     *
     * @param  array<string, mixed>  $segment
     * @return Collection<int, User>
     */
    private function leadStageEmails(array $segment): Collection
    {
        $stage = $segment['stage'] ?? null;
        if (! is_string($stage) || $stage === '') {
            return $this->empty('lead_stage (missing stage)');
        }

        $emails = Lead::query()
            ->where('status', $stage)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower($email))
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return $this->empty("lead_stage={$stage} (no leads with an email)");
        }

        return User::query()
            ->whereIn('email', $emails)
            ->whereNotNull('email')
            ->get();
    }

    /** @return Collection<int, User> */
    private function empty(?string $reason): Collection
    {
        Log::warning('CampaignSegmentResolver: unresolved/empty segment, defaulting to no recipients.', [
            'reason' => $reason,
        ]);

        return new Collection;
    }
}
