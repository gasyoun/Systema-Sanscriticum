<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\HomeworkAutoOpener;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ежечасный проход автооткрытия приёма ДЗ (H1764, волна 1).
 *
 * Ежечасный, а не ежедневный: момент открытия вычисляется точно
 * (`lessons.homework_opens_at`), проход просто доносит его с задержкой не
 * больше часа и переживает перезапуск сервера.
 *
 * Прод-инертна по умолчанию: `homework.auto_open.course_slugs` пуст, пока курс
 * не назван явно, и выборка кандидатов заведомо пуста.
 */
class AutoOpenHomeworkCommand extends Command
{
    protected $signature = 'homework:auto-open
        {--dry-run : Показать, что было бы открыто, не трогая базу и не отправляя пушей}
        {--backfill-last : Открыть по одному самому свежему прошедшему уроку каждого курса, без уведомлений}';

    protected $description = 'Открыть приём домашних заданий по урокам, у которых наступил расчётный момент открытия';

    public function handle(HomeworkAutoOpener $opener): int
    {
        if (! config('homework.auto_open.enabled')) {
            $this->warn('homework.auto_open.enabled = false — ничего не делаю.');

            return self::SUCCESS;
        }

        // H3078: охват больше не список слагов, а правило (дата первого урока +
        // denylist). Пустой `course_slugs` теперь НЕ значит «фича спит» — это
        // значит «исключений из общего охвата нет». Прежний ранний выход здесь
        // был бы тем же тихим отказом, ради устранения которого всё и делалось.
        if ((string) config('homework.auto_open.scope', 'all') === 'listed'
            && (array) config('homework.auto_open.course_slugs', []) === []) {
            $this->warn('scope=listed, но course_slugs пуст — ни один курс не в охвате, ничего не делаю.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $backfill = (bool) $this->option('backfill-last');

        $lessons = $backfill ? $opener->backfillCandidates() : $opener->due();

        if ($lessons->isEmpty()) {
            $this->info($backfill
                ? 'Бэкфилл: подходящих уроков нет.'
                : 'Открывать нечего — ни у одного урока в охвате не наступил момент открытия.');

            return self::SUCCESS;
        }

        $this->renderTable($lessons, $dryRun);

        if ($dryRun) {
            $this->info(sprintf('--dry-run: %d урок(ов) были бы открыты. База не тронута, пуши не отправлены.', $lessons->count()));

            return self::SUCCESS;
        }

        $opened = 0;
        foreach ($lessons as $lesson) {
            // Бэкфилл открывает молча (D11): уведомлять о задании, которое
            // «должно было открыться вчера», значит слать пуш задним числом.
            if ($opener->open($lesson, notify: ! $backfill)) {
                $opened++;
            }
        }

        $this->info(sprintf(
            '%s: открыто %d из %d.%s',
            $backfill ? 'Бэкфилл' : 'Автооткрытие',
            $opened,
            $lessons->count(),
            $backfill ? ' Уведомления не отправлялись.' : '',
        ));

        return self::SUCCESS;
    }

    /** @param  Collection<int, Lesson>  $lessons */
    private function renderTable(Collection $lessons, bool $dryRun): void
    {
        $this->table(
            ['Урок', 'Курс', 'Занятие', $dryRun ? 'Откроется' : 'Момент открытия'],
            $lessons->map(fn (Lesson $lesson) => [
                $lesson->title ?: "#{$lesson->id}",
                $lesson->course?->slug ?: '—',
                $lesson->textbook_lesson ?? '—',
                $lesson->homework_opens_at?->timezone('Europe/Moscow')->format('d.m.Y H:i') ?? '—',
            ])->all(),
        );
    }
}
