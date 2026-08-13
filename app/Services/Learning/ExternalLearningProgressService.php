<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\ActivityEvent;
use App\Models\ExternalLearningProgress;
use App\Models\User;
use App\Services\Activity\ActivityTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Current-state projection for VisualDCS objects. ActivityEvent is the audit stream.
 */
final class ExternalLearningProgressService
{
    public const STATUSES = [
        ExternalLearningProgress::STATUS_STARTED,
        ExternalLearningProgress::STATUS_COMPLETED,
        ExternalLearningProgress::STATUS_MASTERED,
    ];

    public function __construct(
        private readonly ExternalLearningCatalog $catalog,
        private readonly ActivityTracker $tracker,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function upsert(
        User $user,
        string $objectId,
        string $status,
        ?int $score = null,
        array $metadata = [],
        ?Request $request = null,
    ): ExternalLearningProgress {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown progress status: '.$status);
        }

        $item = $this->catalog->mustFind($objectId);
        $provider = (string) config('visualdcs.provider', 'visualdcs');

        $row = DB::transaction(function () use ($user, $objectId, $status, $score, $metadata, $item, $provider) {
            $existing = ExternalLearningProgress::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->where('object_id', $objectId)
                ->lockForUpdate()
                ->first();

            $now = now();
            $safeMeta = $this->boundedMetadata($metadata);

            if (! $existing) {
                return ExternalLearningProgress::query()->create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'object_id' => $objectId,
                    'surface' => (string) $item['surface'],
                    'status' => $status,
                    'score' => $score,
                    'attempts' => 1,
                    'first_started_at' => $now,
                    'last_seen_at' => $now,
                    'completed_at' => $this->completedAt(null, $status, $now),
                    'metadata' => $safeMeta,
                ]);
            }

            $existing->attempts = (int) $existing->attempts + 1;
            $existing->last_seen_at = $now;
            $existing->status = $this->rankStatus($existing->status, $status);
            if ($score !== null) {
                $existing->score = max((int) ($existing->score ?? 0), $score);
            }
            if ($safeMeta !== []) {
                $existing->metadata = array_merge($existing->metadata ?? [], $safeMeta);
            }
            $existing->completed_at = $this->completedAt($existing->completed_at, $existing->status, $now);
            $existing->save();

            return $existing->fresh();
        });

        $this->emit($user, $row, $request);

        return $row;
    }

    /**
     * @return list<ExternalLearningProgress>
     */
    public function forUser(User $user, ?string $surface = null): array
    {
        $query = ExternalLearningProgress::query()
            ->where('user_id', $user->id)
            ->where('provider', (string) config('visualdcs.provider', 'visualdcs'))
            ->orderByDesc('last_seen_at');

        if ($surface) {
            $query->where('surface', $surface);
        }

        return $query->get()->all();
    }

    public function latestForUser(User $user): ?ExternalLearningProgress
    {
        return ExternalLearningProgress::query()
            ->where('user_id', $user->id)
            ->where('provider', (string) config('visualdcs.provider', 'visualdcs'))
            ->orderByDesc('last_seen_at')
            ->first();
    }

    public function countsReconcile(User $user): bool
    {
        $projection = ExternalLearningProgress::query()
            ->where('user_id', $user->id)
            ->where('provider', (string) config('visualdcs.provider', 'visualdcs'))
            ->count();

        $events = ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('event_type', [
                ActivityEvent::VISUALDCS_PROGRESS_STARTED,
                ActivityEvent::VISUALDCS_PROGRESS_COMPLETED,
            ])
            ->count();

        return $events >= $projection;
    }

    private function rankStatus(string $current, string $incoming): string
    {
        $rank = [
            ExternalLearningProgress::STATUS_STARTED => 1,
            ExternalLearningProgress::STATUS_COMPLETED => 2,
            ExternalLearningProgress::STATUS_MASTERED => 3,
        ];

        return ($rank[$incoming] ?? 0) >= ($rank[$current] ?? 0) ? $incoming : $current;
    }

    private function completedAt(mixed $existing, string $status, $now)
    {
        if ($existing) {
            return $existing;
        }

        return in_array($status, [
            ExternalLearningProgress::STATUS_COMPLETED,
            ExternalLearningProgress::STATUS_MASTERED,
        ], true) ? $now : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function boundedMetadata(array $metadata): array
    {
        $allow = ['source', 'device', 'note'];
        $out = [];
        foreach ($allow as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }
            $value = $metadata[$key];
            if (is_string($value)) {
                $out[$key] = mb_substr($value, 0, 120);
            }
        }

        return $out;
    }

    private function emit(User $user, ExternalLearningProgress $row, ?Request $request): void
    {
        $type = in_array($row->status, [
            ExternalLearningProgress::STATUS_COMPLETED,
            ExternalLearningProgress::STATUS_MASTERED,
        ], true)
            ? ActivityEvent::VISUALDCS_PROGRESS_COMPLETED
            : ActivityEvent::VISUALDCS_PROGRESS_STARTED;

        $this->tracker->logEvent($user, null, $type, [
            'provider' => $row->provider,
            'object_id' => $row->object_id,
            'surface' => $row->surface,
            'status' => $row->status,
            'attempts' => $row->attempts,
        ], $request);
    }
}
