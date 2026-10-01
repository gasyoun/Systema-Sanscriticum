<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Services\TeacherSalaryService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Сверка перед включением features.salary_returns_student_refunds_only.
 * ТОЛЬКО ЧТЕНИЕ: ничего не пишет, флаг в конфиге не меняет (переключает его
 * в памяти процесса, чтобы посчитать оба варианта).
 *
 * По каждому преподавателю × курсу × блоку: база блока «сейчас» и «с флагом»,
 * и какие строки «Расход» перестанут резать базу (выплаты преподавателям,
 * реклама, налоги). Решение о включении — за финансовым руководителем.
 */
class SalaryRefundFilterReport extends Command
{
    protected $signature = 'salary:refund-filter-report
        {--teacher= : id или часть имени преподавателя}
        {--rows : показать строки «Расход», которые перестанут вычитаться}';

    protected $description = 'Сверка: база блоков ЗП до/после «вычитать только возвраты студентам» (только чтение)';

    public function handle(): int
    {
        $flag = 'features.salary_returns_student_refunds_only';
        $original = config($flag);

        $teachers = Teacher::query()->orderBy('name')->get();
        if ($filter = $this->option('teacher')) {
            $teachers = $teachers->filter(fn (Teacher $t): bool => (string) $t->id === (string) $filter
                || mb_stripos((string) $t->name, (string) $filter) !== false);
        }

        $grandDelta = 0.0;
        try {
            foreach ($teachers as $teacher) {
                $rows = [];
                foreach ($teacher->allTaughtCourses() as $course) {
                    if (TeacherSalaryService::isTechnicalCourse($course)) {
                        continue;
                    }
                    $blocks = CourseBlock::query()->where('course_id', $course->id)->orderBy('number')->pluck('number')->all();
                    foreach ($blocks as $block) {
                        config([$flag => false]);
                        $before = app(TeacherSalaryService::class)->blockGroupRevenue($course->id, (int) $block, null, ['teacher_id' => $teacher->id]);
                        config([$flag => true]);
                        $after = app(TeacherSalaryService::class)->blockGroupRevenue($course->id, (int) $block, null, ['teacher_id' => $teacher->id]);
                        if (abs($after - $before) >= 0.01) {
                            $rows[] = [$course->id, mb_substr((string) $course->title, 0, 45), $block, Money::round($before), Money::round($after), Money::round($after - $before)];
                            $grandDelta += $after - $before;
                        }
                    }

                    if ($this->option('rows')) {
                        $this->expenseRowsDropped($course->id);
                    }
                }

                if ($rows !== []) {
                    $this->line('');
                    $this->info("#{$teacher->id} {$teacher->name}");
                    $this->table(['Курс', 'Название', 'Блок', 'База сейчас', 'База с флагом', 'Разница'], $rows);
                }
            }
        } finally {
            config([$flag => $original]);
        }

        $this->line('');
        $this->info('Итого база блоков вырастет на '.number_format(Money::round($grandDelta), 2, ',', ' ').' ₽ (до применения % преподавателя).');
        $this->comment('Уже записанные выплаты не пересчитываются. Включение флага — решение финансового руководителя.');

        return self::SUCCESS;
    }

    private function expenseRowsDropped(int $courseId): void
    {
        config(['features.salary_returns_student_refunds_only' => true]);
        $buyers = Payment::query()->where('course_id', $courseId)->paid()->real()
            ->where('amount', '>', 0)->whereNotIn('tariff', TeacherSalaryService::NON_REVENUE_TARIFFS)
            ->pluck('user_id')->filter()->flip();

        $dropped = Payment::query()->where('course_id', $courseId)->paid()->real()
            ->where('tariff', 'Расход')->whereNull('refund_of_payment_id')->whereNull('start_block')
            ->get()
            ->reject(fn (Payment $p): bool => $p->user_id !== null && $buyers->has($p->user_id));

        foreach ($dropped as $p) {
            $this->line(sprintf('   − #%d %s %s ₽  %s', $p->id, $p->created_at?->format('d.m.Y'), $p->amount, mb_substr((string) $p->transaction_id, 0, 70)));
        }
    }
}
