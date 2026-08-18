<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseBlock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * H3084, шаг 13 — `course_blocks.starts_at` / `ends_at` из дат уроков.
 *
 * Источник ровно один: `lessons.lesson_date`, сгруппированные по
 * `(course_id, block_number)`. Расписание (`schedules`) источником быть не
 * может — у него нет номера блока, и приписать занятие блоку можно было бы
 * только по датам, то есть догадкой.
 *
 * Два случая, когда команда НЕ пишет дату (оба — «источник есть, но он ничего
 * не говорит о границах блока»):
 *
 *   1. у блока нет ни одного урока с датой;
 *   2. ВСЕ блоки курса свелись к одному и тому же единственному дню. Так
 *      выглядит массовый импорт, проставивший всем урокам одну дату загрузки:
 *      на курсе 332 все 15 уроков четырёх блоков стоят на 2025-10-08. Записать
 *      её значило бы выдать артефакт импорта за расписание — а «блок получил
 *      дату наугад» прямо запрещено приёмкой H3084.
 *
 * Оба случая печатаются поимённо, а не молча пропускаются: пустой блок с
 * названной причиной честнее заполненного выдумкой.
 *
 * Заполненные значения не перетираются никогда — ни `starts_at`, ни `ends_at`.
 */
class BackfillCourseBlockDates extends Command
{
    protected $signature = 'courses:backfill-block-dates
        {--apply : Записать выведенные даты в пустые starts_at/ends_at}
        {--course=* : Ограничить курсами (id, можно несколько)}';

    protected $description = 'Вывести даты блоков курса из дат уроков и (по --apply) записать их в пустые поля';

    public function handle(): int
    {
        $courseIds = array_map('intval', (array) $this->option('course'));

        $blocks = CourseBlock::query()
            ->when($courseIds !== [], fn ($q) => $q->whereIn('course_id', $courseIds))
            ->with('course:id,title')
            ->orderBy('course_id')
            ->orderBy('number')
            ->get();

        if ($blocks->isEmpty()) {
            $this->warn('Блоков не найдено.');

            return self::SUCCESS;
        }

        $ranges = $this->lessonRanges($blocks->pluck('course_id')->unique()->map(fn ($id): int => (int) $id)->all());

        $rows = [];
        $toWrite = [];

        foreach ($blocks->groupBy('course_id') as $courseId => $courseBlocks) {
            $courseId = (int) $courseId;
            $courseRanges = $ranges[$courseId] ?? [];
            $degenerate = $this->isDegenerate($courseRanges);

            foreach ($courseBlocks as $block) {
                $number = (int) $block->number;
                $range = $courseRanges[$number] ?? null;
                $title = mb_strimwidth((string) ($block->course?->title ?? '—'), 0, 34, '…');

                if ($range === null) {
                    $rows[] = [$courseId, $title, $number, '—', '—', 'уроков с датой нет — оставляю пустым'];

                    continue;
                }

                if ($degenerate) {
                    $rows[] = [
                        $courseId,
                        $title,
                        $number,
                        $range['first'],
                        $range['last'],
                        'все блоки курса на одну дату — артефакт импорта, не расписание; оставляю пустым',
                    ];

                    continue;
                }

                $hasStart = $block->starts_at !== null;
                $hasEnd = $block->ends_at !== null;

                if ($hasStart && $hasEnd) {
                    $rows[] = [$courseId, $title, $number, $range['first'], $range['last'], 'уже заполнен — не трогаю'];

                    continue;
                }

                $patch = [];
                if (! $hasStart) {
                    $patch['starts_at'] = Carbon::parse($range['first'])->startOfDay();
                }
                if (! $hasEnd) {
                    $patch['ends_at'] = Carbon::parse($range['last'])->endOfDay();
                }

                $toWrite[(int) $block->id] = $patch;

                $rows[] = [
                    $courseId,
                    $title,
                    $number,
                    $range['first'],
                    $range['last'],
                    ($this->option('apply') ? 'записываю' : 'записал бы').' (уроков: '.$range['lessons'].')',
                ];
            }
        }

        $this->table(['Курс', 'Название', 'Блок', 'Первый урок', 'Последний урок', 'Что будет'], $rows);

        if (! $this->option('apply')) {
            $this->warn('Режим отчёта: в базу не записано ничего. Повторите с --apply.');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($toWrite, &$written) {
            foreach ($toWrite as $blockId => $patch) {
                $query = CourseBlock::whereKey($blockId);
                // Пустоту перепроверяем в WHERE — та же защита от гонки с
                // ручной правкой, что в остальных бэкфилах H3083/H3084.
                if (array_key_exists('starts_at', $patch)) {
                    $query->whereNull('starts_at');
                }
                if (array_key_exists('ends_at', $patch)) {
                    $query->whereNull('ends_at');
                }

                $written += $query->update($patch);
            }
        });

        $this->info("Блоков с датами: {$written}.");

        return self::SUCCESS;
    }

    /**
     * Диапазоны дат уроков: course_id => block_number => [first, last, lessons].
     *
     * @param  list<int>  $courseIds
     * @return array<int, array<int, array{first: string, last: string, lessons: int}>>
     */
    private function lessonRanges(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = DB::table('lessons')
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('block_number')
            ->whereNotNull('lesson_date')
            ->selectRaw('course_id, block_number, count(*) as lessons, min(lesson_date) as first_date, max(lesson_date) as last_date')
            ->groupBy('course_id', 'block_number')
            ->get();

        $ranges = [];
        foreach ($rows as $row) {
            $ranges[(int) $row->course_id][(int) $row->block_number] = [
                'first' => Carbon::parse($row->first_date)->toDateString(),
                'last' => Carbon::parse($row->last_date)->toDateString(),
                'lessons' => (int) $row->lessons,
            ];
        }

        return $ranges;
    }

    /**
     * Вырожденный источник: у курса больше одного блока с уроками, и все они
     * сошлись в один и тот же единственный день. Такие даты ничего не говорят
     * о границах блоков — см. шапку класса.
     *
     * @param  array<int, array{first: string, last: string, lessons: int}>  $courseRanges
     */
    private function isDegenerate(array $courseRanges): bool
    {
        if (count($courseRanges) < 2) {
            return false;
        }

        $dates = [];
        foreach ($courseRanges as $range) {
            $dates[] = $range['first'];
            $dates[] = $range['last'];
        }

        return count(array_unique($dates)) === 1;
    }
}
