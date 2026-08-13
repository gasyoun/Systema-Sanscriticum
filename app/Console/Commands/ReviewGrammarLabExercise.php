<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GrammarExercise;
use App\Services\GrammarLab\ExerciseRollback;
use Illuminate\Console\Command;

/**
 *   php artisan grammar-lab:review ex:grammar-lab:ablaut-grades approve
 *   php artisan grammar-lab:review ex:grammar-lab:ablaut-grades reject --note="ambiguous"
 */
class ReviewGrammarLabExercise extends Command
{
    protected $signature = 'grammar-lab:review
                            {exercise_id : Stable exercise id}
                            {decision : approve|reject}
                            {--note= : Reviewer note}
                            {--reviewer=curator}';

    protected $description = 'Store a Grammar Lab review decision; reject rolls back without deleting attempts';

    public function handle(ExerciseRollback $rollback): int
    {
        $id = (string) $this->argument('exercise_id');
        $exercise = GrammarExercise::query()->where('exercise_id', $id)->first();
        if ($exercise === null) {
            $this->error("unknown exercise {$id}");

            return self::FAILURE;
        }

        $row = $rollback->decide(
            $exercise,
            (string) $this->argument('decision'),
            $this->option('note') ? (string) $this->option('note') : null,
            (string) $this->option('reviewer'),
        );
        $this->line('exercise_id='.$row->exercise_id);
        $this->line('status='.$row->status);
        $this->line('content_hash='.$row->content_hash);

        return self::SUCCESS;
    }
}
