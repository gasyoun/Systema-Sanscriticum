<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\GrammarAttempt;
use App\Models\GrammarExercise;
use App\Models\GrammarExerciseReview;
use App\Models\GrammarExerciseVersion;
use RuntimeException;

/**
 * Hide or restore an exercise version. Learner attempts are never deleted.
 */
final class ExerciseRollback
{
    public function kill(GrammarExercise $exercise, string $reason = 'killed'): GrammarExercise
    {
        $exercise->status = GrammarExercise::STATUS_KILLED;
        $exercise->killed_at = now();
        $exercise->save();

        GrammarExerciseReview::query()->create([
            'exercise_id' => $exercise->exercise_id,
            'content_hash' => $exercise->content_hash,
            'decision' => GrammarExerciseReview::REJECT,
            'note' => $reason,
            'reviewer' => 'kill-switch',
            'decided_at' => now(),
        ]);

        return $exercise->fresh() ?? $exercise;
    }

    public function rollback(GrammarExercise $exercise, string $reason = 'reviewer_reject'): GrammarExercise
    {
        $prior = GrammarExerciseVersion::query()
            ->where('grammar_exercise_id', $exercise->id)
            ->where('content_hash', '!=', $exercise->content_hash)
            ->where('version_n', '<', (int) $exercise->version_n)
            ->orderByDesc('version_n')
            ->first();

        $attemptCountBefore = GrammarAttempt::query()
            ->where('exercise_id', $exercise->exercise_id)
            ->count();

        if ($prior === null) {
            $this->kill($exercise, $reason);
        } else {
            $exercise->previous_content_hash = $exercise->content_hash;
            $exercise->payload = $prior->payload;
            $exercise->content_hash = $prior->content_hash;
            $exercise->version_n = (int) $prior->version_n;
            $exercise->status = GrammarExercise::STATUS_PUBLISHED;
            $exercise->killed_at = null;
            $exercise->validation_errors = null;
            $exercise->save();

            GrammarExerciseReview::query()->create([
                'exercise_id' => $exercise->exercise_id,
                'content_hash' => $prior->content_hash,
                'decision' => GrammarExerciseReview::REJECT,
                'note' => $reason,
                'reviewer' => 'rollback',
                'decided_at' => now(),
            ]);
        }

        $attemptCountAfter = GrammarAttempt::query()
            ->where('exercise_id', $exercise->exercise_id)
            ->count();
        if ($attemptCountAfter !== $attemptCountBefore) {
            throw new RuntimeException('rollback mutated attempt history');
        }

        return $exercise->fresh() ?? $exercise;
    }

    public function decide(GrammarExercise $exercise, string $decision, ?string $note = null, ?string $reviewer = null): GrammarExercise
    {
        $decision = strtolower($decision);
        if (! in_array($decision, [GrammarExerciseReview::APPROVE, GrammarExerciseReview::REJECT], true)) {
            throw new RuntimeException("unknown review decision {$decision}");
        }

        GrammarExerciseReview::query()->create([
            'exercise_id' => $exercise->exercise_id,
            'content_hash' => $exercise->content_hash,
            'decision' => $decision,
            'note' => $note,
            'reviewer' => $reviewer ?? 'reviewer',
            'decided_at' => now(),
        ]);

        if ($decision === GrammarExerciseReview::REJECT) {
            return $this->rollback($exercise, $note ?? 'reviewer_reject');
        }

        return $exercise;
    }
}
