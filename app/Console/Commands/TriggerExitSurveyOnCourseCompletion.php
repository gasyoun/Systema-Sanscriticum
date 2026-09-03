<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\ExitSurveyAutoTrigger;
use Illuminate\Console\Command;

/**
 * H3915 — ручной/догоняющий прогон Exit-опроса по завершённым курсам.
 * Авто-триггер живёт в Course::updated (is_completed false→true); эта команда
 * нужна, когда флаг включили ПОСЛЕ того, как курсы уже завершены, и для
 * повторной отправки (--force сбрасывает дедуп по штампу). В расписание не
 * заведена — событийного пути достаточно.
 */
class TriggerExitSurveyOnCourseCompletion extends Command
{
    protected $signature = 'surveys:exit-survey-completed
        {--course= : Разобрать только этот курс (id или slug)}
        {--force : Игнорировать дедуп exit_survey_triggered_at}';

    protected $description = 'Завершённые курсы: задача куратору на Exit-опрос (черновики для личной отправки, не рассылка).';

    public function handle(ExitSurveyAutoTrigger $trigger): int
    {
        if (! config('features.exit_survey_auto_trigger', false)) {
            $this->warn('Флаг exit_survey_auto_trigger выключен (EXIT_SURVEY_AUTO_TRIGGER=false) — прогон не делается.');

            return self::FAILURE;
        }

        $courses = Course::query()
            ->when($this->option('course') !== null, fn ($q) => $q->whereKey($this->option('course')))
            ->where('is_completed', true)
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('exit_survey_triggered_at'))
            ->orderBy('id')
            ->get();

        if ($courses->isEmpty()) {
            $this->info('Завершённых курсов без штампа нет — разбирать нечего.');

            return self::SUCCESS;
        }

        $usersTotal = 0;
        foreach ($courses as $course) {
            $users = $trigger->run($course, (bool) $this->option('force'));
            $usersTotal += $users->count();
            $this->line(sprintf(
                'Курс «%s» (#%d): когорта %d, уведомление кураторам %s.',
                $course->title,
                $course->id,
                $users->count(),
                $users->isNotEmpty() ? 'отправлено' : 'не требуется (когорта пуста)',
            ));
        }

        $this->info("Курсов разобрано: {$courses->count()}, студентов в когортах: {$usersTotal}.");

        return self::SUCCESS;
    }
}
