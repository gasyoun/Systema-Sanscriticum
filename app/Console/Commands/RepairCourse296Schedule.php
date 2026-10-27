<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Schedule;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Одноразовое восстановление после каскада CourseBlock.ends_at от 20-10-2026.
 * По умолчанию только показывает план; запись возможна с --apply и только
 * после проверки всего исходного набора из 57 строк внутри транзакции.
 */
final class RepairCourse296Schedule extends Command
{
    private const COURSE_ID = 296;

    private const FIRST_ID = 2223;

    private const LAST_ID = 2279;

    private const EXPECTED_COUNT = 57;

    private const DELTA_SECONDS = 105 * 86400 - 59;

    protected $signature = 'schedules:repair-course-296-block-end-shift
        {--apply : Восстановить строки (без флага — только dry-run)}';

    protected $description = 'Dry-run/восстановление 57 занятий курса 296 после ошибочного каскада ends_at';

    public function handle(): int
    {
        try {
            $plan = $this->plan($this->rows());

            $this->table(
                ['ID', 'Занятие', 'Сейчас', 'Восстановить'],
                collect($plan)->map(fn (array $item): array => [
                    $item['row']->id,
                    $item['row']->title,
                    $item['row']->start->toDateTimeString(),
                    $item['start']->toDateTimeString(),
                ])->all(),
            );

            if (! $this->option('apply')) {
                $this->info('Dry-run: 57 строк проверены, ничего не записано. Для записи нужен --apply.');

                return self::SUCCESS;
            }

            DB::transaction(function (): void {
                // Повторно читаем и проверяем весь набор под блокировкой.
                $plan = $this->plan($this->rows(lock: true));

                foreach ($plan as $item) {
                    $row = $item['row'];
                    $row->start = $item['start'];
                    $row->end = $item['end'];
                    $row->save();
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        Log::info('Course 296 schedule restored after ends_at cascade', ['count' => self::EXPECTED_COUNT]);
        $this->info('Восстановлено 57 занятий курса 296.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Schedule>
     */
    private function rows(bool $lock = false): Collection
    {
        $query = Schedule::query()
            ->where('course_id', self::COURSE_ID)
            ->whereBetween('id', [self::FIRST_ID, self::LAST_ID])
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Schedule>  $rows
     * @return list<array{row: Schedule, start: \Carbon\Carbon, end: \Carbon\Carbon}>
     */
    private function plan(Collection $rows): array
    {
        if ($rows->count() !== self::EXPECTED_COUNT) {
            throw new RuntimeException("Ожидалось 57 строк, найдено {$rows->count()}. Ничего не изменено.");
        }

        $plan = [];

        foreach ($rows as $row) {
            if ($row->start === null || $row->end === null) {
                throw new RuntimeException("У строки {$row->id} нет start/end. Ничего не изменено.");
            }

            $start = $row->start->copy()->subSeconds(self::DELTA_SECONDS);
            $end = $row->end->copy()->subSeconds(self::DELTA_SECONDS);
            $local = $start->copy()->timezone('Europe/Moscow');
            preg_match('/(\d{2}\.\d{2}\.\d{2})/u', (string) $row->title, $matches);

            if (($matches[1] ?? null) !== $local->format('d.m.y')
                || $local->format('H:i:s') !== '10:00:00'
                || (int) $row->start->diffInSeconds($row->end) !== 7200) {
                throw new RuntimeException("Строка {$row->id} не совпадает с отпечатком инцидента. Ничего не изменено.");
            }

            $plan[] = ['row' => $row, 'start' => $start, 'end' => $end];
        }

        return $plan;
    }
}
