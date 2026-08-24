<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\MembershipAccessVerdict;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class RecordingAccessPolicy
{
    public function __construct(private readonly ClubEntitlement $membership) {}

    public function decide(
        User $user,
        Course $course,
        Lesson $lesson,
        bool $hasLessonGrant = false,
        string $surface = 'web',
    ): RecordingAccessDecision {
        if (! $this->hasRecording($lesson)) {
            return new RecordingAccessDecision(true, true, false, 'off', 'no_recording');
        }

        $openLectureAccess = $this->hasOpenLectureRecordingAccess($user, $course, $lesson);

        $wouldAllow = $lesson->is_free
            || $lesson->is_preview
            || $hasLessonGrant
            || ($this->hasPurchasedLesson($user, $course, $lesson)
                && $this->membership->allows($user, 'recordings'))
            || $this->membership->coversCourse($user, $course)
            || $openLectureAccess;

        $reason = match (true) {
            $lesson->is_free || $lesson->is_preview => 'free_or_preview',
            $hasLessonGrant => 'lesson_grant',
            $openLectureAccess => 'open_lecture',
            ! $this->hasPurchasedLesson($user, $course, $lesson)
                && ! $this->membership->coversCourse($user, $course) => 'course_not_purchased',
            $wouldAllow => 'club_or_top',
            default => 'tier_below_club',
        };

        $mode = $this->modeFor($user);
        $shadow = in_array($mode, ['shadow', 'pilot-shadow'], true);
        $allowed = ! in_array($mode, ['pilot', 'full'], true) || $wouldAllow;

        if ($mode !== 'off') {
            MembershipAccessVerdict::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'lesson_id' => $lesson->id,
                'surface' => $surface,
                'mode' => $mode,
                'would_allow' => $wouldAllow,
                'reason' => $reason,
                'requested_at' => now(),
            ]);
        }

        return new RecordingAccessDecision($allowed, $wouldAllow, $shadow, $mode, $reason);
    }

    public function modeFor(User $user): string
    {
        if ((bool) config('features.membership_recording_enforce', false)) {
            return 'full';
        }

        if ((bool) config('features.membership_recording_pilot', false)) {
            $ids = $this->pilotUserIds();
            $startedAt = $this->pilotStartedAt();
            if (count($ids) !== 20 || ! $startedAt instanceof Carbon) {
                Log::critical('membership recording pilot misconfigured; enforcement failed open', [
                    'pilot_users' => count($ids),
                    'started_at' => config('membership.recording_gate.pilot_started_at'),
                ]);

                return 'pilot-shadow';
            }

            $withinWindow = now()->betweenIncluded($startedAt, $startedAt->copy()->addHours(48));

            return $withinWindow && in_array((int) $user->id, $ids, true) ? 'pilot' : 'pilot-shadow';
        }

        return (bool) config('features.membership_recording_shadow', false) ? 'shadow' : 'off';
    }

    /** @return list<int> */
    public function pilotUserIds(): array
    {
        return collect(explode(',', (string) config('membership.recording_gate.pilot_user_ids', '')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function pilotStartedAt(): ?Carbon
    {
        $value = trim((string) config('membership.recording_gate.pilot_started_at', ''));

        return $value === '' ? null : Carbon::parse($value);
    }

    private function hasRecording(Lesson $lesson): bool
    {
        return filled($lesson->youtube_url) || filled($lesson->rutube_url) || filled($lesson->video_url);
    }

    /**
     * Открытые лекции МГ (план института N5, решение MG 23-08 поздним вечером):
     * живьём лекция бесплатна всем и этой политикой не трогается (она гейтит
     * только видеопayload записей); запись бесплатна при ЛЮБОМ действующем
     * платном членстве (Basic ₽1 000 и Club ₽2 000 — уточнение против клубного
     * минимума 'club' в capabilities), а неплатный покупает запись как обычный
     * товар курса — без требования членства.
     *
     * Применяется ТОЛЬКО к курсам из institute.lecture_course_ids: остальные
     * курсы живут прежними правилами. Роллаут — тот же staged-конвейер
     * off → shadow → pilot → full, отдельного пути нет.
     */
    private function hasOpenLectureRecordingAccess(User $user, Course $course, Lesson $lesson): bool
    {
        /** @var list<int> $lectureCourseIds */
        $lectureCourseIds = config('institute.lecture_course_ids', []);

        if (! in_array((int) $course->id, $lectureCourseIds, true)) {
            return false;
        }

        // Любое платное членство (basic и выше) — запись включена всегда.
        $tier = $this->membership->activeTierFor($user);

        if ($tier !== null && $tier !== MembershipTier::Free) {
            return true;
        }

        // Неплатный покупает саму запись тарифом этого курса.
        return $this->hasPurchasedLesson($user, $course, $lesson);
    }

    private function hasPurchasedLesson(User $user, Course $course, Lesson $lesson): bool
    {
        $keys = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->paid()
            ->real()
            ->where('amount', '>', 0)
            ->pluck('tariff')
            ->all();

        return $lesson->isUnlockedBy($keys);
    }
}
