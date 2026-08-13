<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\GrammarExercise;
use App\Models\GrammarExerciseReview;
use App\Models\GrammarExerciseVersion;

/**
 * Risk-tiered publication (H2494).
 *
 * Deterministic items may auto-publish only when validators pass, the
 * reproducible 20% sample is drawn, and rollback versions exist.
 * Interpretive items never publish without an approval_record.
 * Kill switch blocks new auto-publication; it does not delete attempts.
 */
final class ExercisePublisher
{
    public function __construct(
        private readonly ExerciseValidator $validator,
        private readonly ExerciseSampler $sampler,
    ) {}

    public function autoPublishEnabled(): bool
    {
        return (bool) config('grammar_lab.publication.auto_publish', true);
    }

    /**
     * @param  array<string, mixed>  $exercise
     */
    public function ingest(array $exercise): GrammarExercise
    {
        $id = (string) ($exercise['id'] ?? '');
        $hash = $this->contentHash($exercise);
        $row = GrammarExercise::query()->firstOrNew(['exercise_id' => $id]);
        $previousHash = $row->exists ? (string) $row->content_hash : null;
        $hashChanged = $row->exists && $previousHash !== $hash;

        $row->topic_id = (string) ($exercise['topic_id'] ?? '');
        $row->risk_class = (string) ($exercise['risk_class'] ?? 'deterministic');
        $row->payload = $exercise;
        if ($hashChanged) {
            $row->previous_content_hash = $previousHash;
            $row->version_n = (int) $row->version_n + 1;
        } elseif (! $row->exists) {
            $row->version_n = 1;
        }
        $row->content_hash = $hash;
        $row->approval_record = $this->approvalOf($exercise);
        $row->validation_errors = null;
        $row->save();

        $this->recordVersion($row, $exercise, GrammarExercise::STATUS_IMPORTED);

        return $this->applyGates($row);
    }

    public function applyGates(GrammarExercise $row): GrammarExercise
    {
        $exercise = is_array($row->payload) ? $row->payload : [];
        $errors = $this->validator->errors($exercise);

        if (($row->risk_class === 'interpretive') && $this->approvalOf($exercise) === null) {
            $row->status = GrammarExercise::STATUS_APPROVAL_REQUIRED;
            $row->validation_errors = array_values(array_unique(array_merge($errors, ['interpretive_needs_approval'])));
            $row->save();

            return $row;
        }

        if ($errors !== []) {
            $row->status = GrammarExercise::STATUS_REJECTED;
            $row->validation_errors = $errors;
            $row->save();

            return $row;
        }

        if (! $this->autoPublishEnabled()) {
            $row->status = GrammarExercise::STATUS_IMPORTED;
            $row->validation_errors = ['auto_publish_killed'];
            $row->save();

            return $row;
        }

        $row->status = GrammarExercise::STATUS_PUBLISHED;
        $row->validation_errors = null;
        $row->killed_at = null;
        $row->save();
        $this->recordVersion($row, $exercise, GrammarExercise::STATUS_PUBLISHED);

        return $row;
    }

    /**
     * Draw the 20% sample over currently published deterministic exercises
     * and persist sampled review rows + a machine-readable manifest.
     *
     * @return array{sampled: list<string>, path: string, pool: list<string>}
     */
    public function drawReviewSample(?string $manifestPath = null): array
    {
        $pool = GrammarExercise::query()
            ->where('status', GrammarExercise::STATUS_PUBLISHED)
            ->where('risk_class', 'deterministic')
            ->orderBy('exercise_id')
            ->pluck('exercise_id')
            ->all();
        $sampled = $this->sampler->draw($pool);

        GrammarExercise::query()->update(['in_review_sample' => false]);
        if ($sampled !== []) {
            GrammarExercise::query()
                ->whereIn('exercise_id', $sampled)
                ->update(['in_review_sample' => true]);
        }

        $decisions = [];
        foreach ($sampled as $id) {
            $ex = GrammarExercise::query()->where('exercise_id', $id)->first();
            if ($ex === null) {
                continue;
            }
            $existing = GrammarExerciseReview::query()
                ->where('exercise_id', $id)
                ->where('content_hash', $ex->content_hash)
                ->where('decision', GrammarExerciseReview::SAMPLED)
                ->first();
            if ($existing === null) {
                GrammarExerciseReview::query()->create([
                    'exercise_id' => $id,
                    'content_hash' => $ex->content_hash,
                    'decision' => GrammarExerciseReview::SAMPLED,
                    'note' => 'reproducible 20% sample',
                ]);
            }
            $latest = GrammarExerciseReview::query()
                ->where('exercise_id', $id)
                ->whereNotNull('decided_at')
                ->orderByDesc('id')
                ->first();
            $decisions[$id] = $latest?->decision ?? GrammarExerciseReview::SAMPLED;
        }

        $manifest = $this->sampler->manifest($pool, $sampled, $decisions);
        $path = $this->sampler->write($manifest, $manifestPath);

        return ['sampled' => $sampled, 'path' => $path, 'pool' => $pool];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordVersion(GrammarExercise $row, array $payload, string $status): void
    {
        $existing = GrammarExerciseVersion::query()
            ->where('grammar_exercise_id', $row->id)
            ->where('version_n', (int) $row->version_n)
            ->where('content_hash', $row->content_hash)
            ->first();
        if ($existing) {
            if ($existing->status !== $status) {
                $existing->status = $status;
                $existing->save();
            }

            return;
        }

        GrammarExerciseVersion::query()->create([
            'grammar_exercise_id' => $row->id,
            'exercise_id' => $row->exercise_id,
            'version_n' => (int) $row->version_n,
            'content_hash' => $row->content_hash,
            'status' => $status,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function contentHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $exercise
     */
    private function approvalOf(array $exercise): ?string
    {
        $value = $exercise['approval_record'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
