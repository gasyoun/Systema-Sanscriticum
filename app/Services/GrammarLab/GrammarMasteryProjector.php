<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\GrammarAttempt;
use App\Models\GrammarExercise;
use App\Models\GrammarMastery;
use App\Models\User;

/**
 * Attempt → mastery projection. Does not write srs_review_states.
 */
final class GrammarMasteryProjector
{
    public function __construct(
        private readonly ExerciseValidator $validator,
    ) {}

    public function record(User $user, GrammarExercise $exercise, string $given): GrammarAttempt
    {
        $payload = is_array($exercise->payload) ? $exercise->payload : [];
        $correct = $this->validator->answersMatch($payload, $given);

        $attempt = GrammarAttempt::query()->create([
            'user_id' => $user->id,
            'topic_id' => $exercise->topic_id,
            'exercise_id' => $exercise->exercise_id,
            'content_hash' => $exercise->content_hash,
            'given' => $given,
            'correct' => $correct,
            'attempted_at' => now(),
        ]);

        $this->project($user, $exercise->topic_id, $correct);

        return $attempt;
    }

    public function project(User $user, string $topicId, bool $correct): GrammarMastery
    {
        $needed = (int) config('grammar_lab.mastery.correct_to_master', 2);
        $row = GrammarMastery::query()->firstOrNew([
            'user_id' => $user->id,
            'topic_id' => $topicId,
        ]);
        $row->attempt_count = (int) $row->attempt_count + 1;
        if ($correct) {
            $row->correct_count = (int) $row->correct_count + 1;
            $row->correct_streak = (int) $row->correct_streak + 1;
        } else {
            $row->correct_streak = 0;
        }
        $row->last_attempted_at = now();
        if ($row->correct_streak >= $needed) {
            $row->state = GrammarMastery::MASTERED;
        } else {
            $row->state = GrammarMastery::LEARNING;
        }
        $row->save();

        return $row;
    }

    public function for(User $user, string $topicId): ?GrammarMastery
    {
        return GrammarMastery::query()
            ->where('user_id', $user->id)
            ->where('topic_id', $topicId)
            ->first();
    }
}
