<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\Course;
use App\Models\GrammarLabPilotEvent;
use App\Models\GrammarLabPilotParticipant;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H2495 / G4 — consented pilot cohort, events, and anonymized aggregates.
 *
 * Flag OFF → enroll/record no-op and routes 404. Never exports identity.
 */
final class GrammarLabPilot
{
    public const EVENT_TASK = 'task_complete';

    public const EVENT_QUIZ = 'quiz';

    public const EVENT_RETURN = 'return';

    public const EVENT_CONFUSION = 'confusion';

    public const CONFUSION_CODES = [
        'whitney_zalizniak_mismatch',
        'search_miss',
        'exercise_unclear',
        'term_unknown',
        'other',
    ];

    public function flagOn(): bool
    {
        return (bool) config('features.grammar_lab_pilot', false);
    }

    /**
     * @return list<string>
     */
    public function eligibilityCourseSlugs(): array
    {
        $slugs = config('grammar_lab.pilot.eligibility_course_slugs', []);
        if (! is_array($slugs) || $slugs === []) {
            $slugs = config('grammar_lab.entitlement_course_slugs', []);
        }

        return is_array($slugs) ? array_values(array_filter(array_map('strval', $slugs))) : [];
    }

    /**
     * Current students with a real paid key on a configured intermediate course.
     *
     * @return Builder<User>
     */
    public function eligibleUsers(): Builder
    {
        $slugs = $this->eligibilityCourseSlugs();
        if ($slugs === []) {
            return User::query()->whereRaw('0 = 1');
        }

        $ids = Course::query()->whereIn('slug', $slugs)->pluck('id');
        if ($ids->isEmpty()) {
            return User::query()->whereRaw('0 = 1');
        }

        return User::query()->whereIn('id', Payment::query()
            ->select('user_id')
            ->whereIn('course_id', $ids)
            ->paid()
            ->real()
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
            ->whereNotNull('user_id'));
    }

    public function isEligible(User $user): bool
    {
        return $this->eligibleUsers()->whereKey($user->id)->exists();
    }

    public function enroll(User $user, string $priorExposure, ?string $cohort = null): GrammarLabPilotParticipant
    {
        $existing = GrammarLabPilotParticipant::query()->where('user_id', $user->id)->first();
        if ($existing instanceof GrammarLabPilotParticipant) {
            return $existing;
        }

        return GrammarLabPilotParticipant::query()->create([
            'user_id' => $user->id,
            'prior_exposure' => $priorExposure,
            'cohort' => $cohort ?: $this->assignCohort($priorExposure, $user->id),
            'consented_at' => now(),
        ]);
    }

    public function participantFor(User $user): ?GrammarLabPilotParticipant
    {
        return GrammarLabPilotParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('withdrawn_at')
            ->first();
    }

    public function record(User $user, string $type, array $payload = []): ?GrammarLabPilotEvent
    {
        if (! $this->flagOn()) {
            return null;
        }
        $participant = $this->participantFor($user);
        if (! $participant instanceof GrammarLabPilotParticipant) {
            return null;
        }

        return GrammarLabPilotEvent::query()->create([
            'participant_id' => $participant->id,
            'event_type' => $type,
            'topic_id' => isset($payload['topic_id']) ? (string) $payload['topic_id'] : null,
            'payload' => $payload,
        ]);
    }

    /**
     * Anonymized aggregates. Identity never leaves this method.
     *
     * @return array<string, mixed>
     */
    public function report(?Carbon $from = null, ?Carbon $to = null): array
    {
        $consented = GrammarLabPilotParticipant::query()->whereNotNull('consented_at');
        $nConsented = (clone $consented)->count();
        $nWithdrawn = GrammarLabPilotParticipant::query()->whereNotNull('withdrawn_at')->count();
        $nActive = (clone $consented)->whereNull('withdrawn_at')->count();

        $events = GrammarLabPilotEvent::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get();

        $taskCompleters = $this->distinctParticipants($events, self::EVENT_TASK);
        $quizEvents = $events->where('event_type', self::EVENT_QUIZ);
        $quizCorrect = $quizEvents->filter(fn ($e) => (bool) data_get($e->payload, 'correct'))->count();
        $returners = $this->returnUseParticipants($events);
        $confusion = $events->where('event_type', self::EVENT_CONFUSION);
        $confusionCodes = [];
        foreach (self::CONFUSION_CODES as $code) {
            $confusionCodes[$code] = $confusion->filter(
                fn ($e) => (string) data_get($e->payload, 'code') === $code
            )->count();
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'pilot_authorized' => false,
            'note' => 'Pilot cohort is not human-authorized. Counts are from sandbox/instrumentation only. n=5–10 cannot support population-level learning claims.',
            'n_consented' => $nConsented,
            'n_active' => $nActive,
            'n_withdrawn' => $nWithdrawn,
            'task_completion' => [
                'numerator' => $taskCompleters->count(),
                'denominator' => $nActive,
                'missing' => max(0, $nActive - $taskCompleters->count()),
            ],
            'quiz_accuracy' => [
                'numerator' => $quizCorrect,
                'denominator' => $quizEvents->count(),
                'missing' => 0,
            ],
            'return_use' => [
                'numerator' => $returners->count(),
                'denominator' => $nActive,
                'missing' => max(0, $nActive - $returners->count()),
            ],
            'confusion' => [
                'numerator' => $confusion->count(),
                'denominator' => $nActive,
                'coded' => $confusionCodes,
            ],
        ];
    }

    public function assignCohort(string $priorExposure, int $userId): string
    {
        $counts = GrammarLabPilotParticipant::query()
            ->where('prior_exposure', $priorExposure)
            ->selectRaw('cohort, count(*) as c')
            ->groupBy('cohort')
            ->pluck('c', 'cohort');
        $a = (int) ($counts['A'] ?? 0);
        $b = (int) ($counts['B'] ?? 0);
        if ($a === $b) {
            return crc32('h2495-pilot-cohort:'.$userId) % 2 === 0 ? 'A' : 'B';
        }

        return $a < $b ? 'A' : 'B';
    }

    /**
     * @param  Collection<int, GrammarLabPilotEvent>  $events
     * @return Collection<int, int>
     */
    private function distinctParticipants(Collection $events, string $type): Collection
    {
        return $events->where('event_type', $type)->pluck('participant_id')->unique()->values();
    }

    /**
     * Return use = activity on at least two distinct UTC dates.
     *
     * @param  Collection<int, GrammarLabPilotEvent>  $events
     * @return Collection<int, int>
     */
    private function returnUseParticipants(Collection $events): Collection
    {
        return $events
            ->groupBy('participant_id')
            ->filter(function (Collection $rows): bool {
                $days = $rows->map(fn ($e) => optional($e->created_at)->toDateString())->unique()->filter();

                return $days->count() >= 2;
            })
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values();
    }
}
