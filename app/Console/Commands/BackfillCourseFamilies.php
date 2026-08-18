<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Support\CourseFamilyMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * H3083 — заполнение `courses.course_family` по названиям курсов.
 *
 * По умолчанию НИЧЕГО НЕ ПИШЕТ: печатает таблицу «курс → предлагаемая семья →
 * роль → номер» и выходит. Это тот случай, где авто заведомо ошибётся на
 * нестандартном названии (курс 424 под шаблон «(N поток, ГОД)» не подходит),
 * поэтому запись включается явным `--apply`.
 *
 * Заполненные вручную значения не трогаются никогда — ни при каком заголовке.
 */
class BackfillCourseFamilies extends Command
{
    protected $signature = 'courses:backfill-families
        {--apply : Записать предложенные значения в пустые course_family (заполненные не трогаются)}
        {--only-multi : Показать/записать только семьи, в которых больше одного курса}';

    protected $description = 'Предложить (и по --apply записать) courses.course_family по названиям курсов';

    public function handle(CourseFamilyMatcher $matcher): int
    {
        $courses = Course::query()->orderBy('id')->get();

        /** @var array<string, list<array<string, mixed>>> $families */
        $families = [];

        foreach ($courses as $course) {
            $suggested = $matcher->familySlug((string) $course->title);
            if ($suggested === '') {
                continue;
            }

            $blocks = $course->blocks()->count();
            $tariffs = $course->tariffs()->where('is_active', true)->count();
            $paid = $course->payments()->paid()->count();
            $firstPaidAt = $course->payments()->paid()->min('created_at');

            [$ordinal] = $matcher->ordinalFor(
                (string) $course->title,
                $firstPaidAt ? Carbon::parse($firstPaidAt) : null,
            );

            $families[$suggested][] = [
                'course' => $course,
                'suggested' => $suggested,
                'current' => trim((string) ($course->course_family ?? '')),
                'role' => $matcher->streamRole($blocks, $tariffs, $paid),
                'ordinal' => $ordinal,
            ];
        }

        ksort($families);

        $rows = [];
        $toWrite = [];

        foreach ($families as $family => $members) {
            if ($this->option('only-multi') && count($members) < 2) {
                continue;
            }

            foreach ($members as $m) {
                /** @var Course $course */
                $course = $m['course'];
                $verdict = match (true) {
                    $m['current'] === $m['suggested'] => 'уже так',
                    $m['current'] !== '' => 'вручную: '.$m['current'].' — НЕ трогаю',
                    default => $this->option('apply') ? 'записываю' : 'записал бы',
                };

                if ($m['current'] === '') {
                    $toWrite[(int) $course->id] = $m['suggested'];
                }

                $rows[] = [
                    $course->id,
                    mb_strimwidth((string) $course->title, 0, 46, '…'),
                    $family.(count($members) > 1 ? ' ('.count($members).')' : ''),
                    $m['role'],
                    $m['ordinal'] ?: '—',
                    $verdict,
                ];
            }
        }

        $this->table(['ID', 'Курс', 'Семья', 'Роль', '№', 'Что будет'], $rows);

        if (! $this->option('apply')) {
            $this->warn('Режим отчёта: в базу не записано ничего. Повторите с --apply.');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($toWrite, &$written) {
            foreach ($toWrite as $courseId => $family) {
                // Условие в WHERE, а не только в PHP: гонка с ручной правкой в
                // админке не должна перетереть человеческое значение.
                $written += Course::whereKey($courseId)
                    ->where(fn ($q) => $q->whereNull('course_family')->orWhere('course_family', ''))
                    ->update(['course_family' => $family]);
            }
        });

        $this->info("Записано семей: {$written}.");

        return self::SUCCESS;
    }
}
