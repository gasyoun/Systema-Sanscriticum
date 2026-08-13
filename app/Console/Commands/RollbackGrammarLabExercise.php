<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GrammarExercise;
use App\Services\GrammarLab\ExerciseRollback;
use Illuminate\Console\Command;

/**
 *   php artisan grammar-lab:rollback ex:grammar-lab:ablaut-grades
 *   php artisan grammar-lab:rollback ex:grammar-lab:ablaut-grades --kill
 */
class RollbackGrammarLabExercise extends Command
{
    protected $signature = 'grammar-lab:rollback
                            {exercise_id : Stable exercise id}
                            {--kill : Hide the current version instead of restoring the previous one}';

    protected $description = 'Roll back or kill a Grammar Lab exercise; attempts are preserved';

    public function handle(ExerciseRollback $rollback): int
    {
        $id = (string) $this->argument('exercise_id');
        $exercise = GrammarExercise::query()->where('exercise_id', $id)->first();
        if ($exercise === null) {
            $this->error("unknown exercise {$id}");

            return self::FAILURE;
        }

        $row = $this->option('kill')
            ? $rollback->kill($exercise, 'operator_kill')
            : $rollback->rollback($exercise, 'operator_rollback');
        $this->line('exercise_id='.$row->exercise_id);
        $this->line('status='.$row->status);
        $this->line('attempts_untouched=true');

        return self::SUCCESS;
    }
}
