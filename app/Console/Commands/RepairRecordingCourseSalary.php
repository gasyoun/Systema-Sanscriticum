<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Support\CourseFamilyMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H3084, шаг 11 — курс-запись получает преподавателя и схему ЗП живого потока
 * своей семьи.
 *
 * Задача из аудита: «Кашмирский шиваизм 2025 в записи» (курс 424) продан пять
 * раз на 51 000 ₽, но `teacher_id` и `salary_type` у него пусты, поэтому 30 %
 * (15 300 ₽) не начислялись НИКОГДА. Курс-запись — это тот же материал того же
 * преподавателя, что и живой поток; условия берутся у него, а не выдумываются.
 *
 * Что команда НЕ делает:
 *
 *   - не трогает курсы, у которых `teacher_id` или `salary_type` уже заполнены
 *     (человек прав по определению);
 *   - не трогает живые потоки — только роль `recording` матчера;
 *   - не выбирает преподавателя, если живые потоки семьи расходятся в условиях:
 *     это ровно тот случай, где авто угадало бы, а угадывать в деньгах нельзя;
 *   - не пишет ни одной строки в `teacher_payouts` и `payments` — начисление
 *     считается из курса и платежей, а не заводится записью.
 *
 * Без `--apply` печатает таблицу и выходит.
 */
class RepairRecordingCourseSalary extends Command
{
    protected $signature = 'salary:repair-recording-courses
        {--apply : Записать teacher_id/salary_type/salary_value в пустые поля курсов-записей}
        {--family= : Ограничить одной семьёй потоков (слаг courses.course_family)}';

    protected $description = 'Проставить курсам-записям преподавателя и схему ЗП живого потока той же семьи';

    public function handle(CourseFamilyMatcher $matcher): int
    {
        $family = trim((string) ($this->option('family') ?? ''));

        $courses = Course::query()
            ->when($family !== '', fn ($q) => $q->where('course_family', $family))
            ->orderBy('id')
            ->get();

        /** @var array<string, Collection<int, Course>> $byFamily */
        $byFamily = $courses
            ->filter(fn (Course $c): bool => trim((string) ($c->course_family ?? '')) !== '')
            ->groupBy(fn (Course $c): string => (string) $c->course_family);

        $rows = [];
        $toWrite = [];

        foreach ($byFamily as $familySlug => $members) {
            $roles = [];
            foreach ($members as $course) {
                $roles[(int) $course->id] = $matcher->streamRole(
                    $course->blocks()->count(),
                    $course->tariffs()->where('is_active', true)->count(),
                    $course->payments()->paid()->count(),
                );
            }

            $recordings = $members->filter(
                fn (Course $c): bool => $roles[(int) $c->id] === CourseFamilyMatcher::ROLE_RECORDING
            );

            if ($recordings->isEmpty()) {
                continue;
            }

            $donorTerms = $this->donorTerms($members, $roles);

            foreach ($recordings as $course) {
                $hasTeacher = $course->teacher_id !== null;
                $hasScheme = trim((string) ($course->salary_type ?? '')) !== '';

                if ($hasTeacher && $hasScheme) {
                    $rows[] = [$course->id, $this->short($course), $familySlug, '—', 'уже заполнен — не трогаю'];

                    continue;
                }

                if ($donorTerms === null) {
                    $rows[] = [
                        $course->id,
                        $this->short($course),
                        $familySlug,
                        '—',
                        'живые потоки семьи расходятся в условиях (или их нет) — пропускаю',
                    ];

                    continue;
                }

                // Частично заполненный курс — тоже подозрительно: заполняем
                // только пустые поля, заполненные оставляем как есть.
                $patch = [];
                if (! $hasTeacher) {
                    $patch['teacher_id'] = $donorTerms['teacher_id'];
                }
                if (! $hasScheme) {
                    $patch['salary_type'] = $donorTerms['salary_type'];
                    $patch['salary_value'] = $donorTerms['salary_value'];
                }

                $toWrite[(int) $course->id] = $patch;

                $rows[] = [
                    $course->id,
                    $this->short($course),
                    $familySlug,
                    sprintf(
                        'препод %d · %s %s (из курса %d)',
                        $donorTerms['teacher_id'],
                        $donorTerms['salary_type'],
                        (string) $donorTerms['salary_value'],
                        $donorTerms['donor_course_id'],
                    ),
                    $this->option('apply') ? 'записываю' : 'записал бы',
                ];
            }
        }

        if ($rows === []) {
            $this->info('Курсов-записей с пустой схемой ЗП не найдено — чинить нечего.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Курс', 'Семья', 'Условия', 'Что будет'], $rows);

        if (! $this->option('apply')) {
            $this->warn('Режим отчёта: в базу не записано ничего. Повторите с --apply.');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($toWrite, &$written) {
            foreach ($toWrite as $courseId => $patch) {
                // Пустоту проверяем ещё раз в WHERE: между отчётом и --apply
                // человек мог заполнить поле руками, и перетирать его нельзя.
                $query = Course::whereKey($courseId);
                if (array_key_exists('teacher_id', $patch)) {
                    $query->whereNull('teacher_id');
                }
                if (array_key_exists('salary_type', $patch)) {
                    $query->where(fn ($q) => $q->whereNull('salary_type')->orWhere('salary_type', ''));
                }

                $written += $query->update($patch);
            }
        });

        $this->info("Курсов починено: {$written}.");

        return self::SUCCESS;
    }

    /**
     * Условия живого потока семьи — только если ВСЕ живые потоки с заполненной
     * схемой согласны между собой. Расхождение = молчаливой догадки не будет.
     *
     * @param  Collection<int, Course>  $members
     * @param  array<int, string>  $roles
     * @return array{teacher_id: int, salary_type: string, salary_value: ?float, donor_course_id: int}|null
     */
    private function donorTerms(Collection $members, array $roles): ?array
    {
        $donors = $members
            ->filter(fn (Course $c): bool => $roles[(int) $c->id] === CourseFamilyMatcher::ROLE_LIVE)
            ->filter(fn (Course $c): bool => $c->teacher_id !== null && trim((string) ($c->salary_type ?? '')) !== '')
            ->sortBy('id')
            ->values();

        if ($donors->isEmpty()) {
            return null;
        }

        $signature = fn (Course $c): string => implode('|', [
            (int) $c->teacher_id,
            (string) $c->salary_type,
            $c->salary_value === null ? '' : (string) (float) $c->salary_value,
        ]);

        if ($donors->map($signature)->unique()->count() > 1) {
            return null;
        }

        /** @var Course $first */
        $first = $donors->first();

        return [
            'teacher_id' => (int) $first->teacher_id,
            'salary_type' => (string) $first->salary_type,
            'salary_value' => $first->salary_value === null ? null : (float) $first->salary_value,
            'donor_course_id' => (int) $first->id,
        ];
    }

    private function short(Course $course): string
    {
        return mb_strimwidth((string) $course->title, 0, 44, '…');
    }
}
