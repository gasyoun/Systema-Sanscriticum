<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Exports\TeacherPayoutsExporter;
use App\Filament\Exports\TeacherSalariesExporter;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Экспорт «Зарплаты» (сводная ведомость) и «История выплат»: строки CSV
 * считаются теми же правилами, что и дашборд (advances, схема-снимок, форматы).
 */
class TeacherExportersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Статический кэш сводки живёт между тестами процесса — сбрасываем.
        $prop = new ReflectionProperty(TeacherSalariesExporter::class, 'summary');
        $prop->setValue(null, null);
    }

    /**
     * Прогоняет запись через экспортёр так же, как это делает job Filament.
     *
     * @return array<int, mixed>
     */
    private function exportRow(string $exporterClass, Model $record): array
    {
        $columnMap = [];
        foreach ($exporterClass::getColumns() as $column) {
            $columnMap[$column->getName()] = $column->getLabel();
        }

        /** @var Exporter $exporter */
        $exporter = new $exporterClass(new Export, $columnMap, []);

        return $exporter($record);
    }

    /** @test */
    public function salaries_export_row_matches_summary_and_excludes_unsettled_advance(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 30,
        ]);

        $user = User::factory()->create();
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 1000,
            'tariff' => 'full', 'status' => 'paid',
        ]));

        // Обычная выплата — идёт в «Выплачено всего».
        $teacher->payouts()->create(['amount' => 100, 'paid_at' => now()->toDateString()]);
        // Непогашенный аванс — в «Выплачено всего» НЕ идёт.
        $teacher->payouts()->create([
            'amount' => 50, 'type' => TeacherPayout::TYPE_ADVANCE, 'paid_at' => now()->toDateString(),
        ]);

        $row = $this->exportRow(TeacherSalariesExporter::class, $teacher);

        // id, name, courses_count, earned_period, returns_period, earned_all_time, paid_all_time, balance
        $this->assertSame((string) $teacher->id, (string) $row[0]);
        $this->assertSame('Препод', $row[1]);
        $this->assertSame('1', (string) $row[2]);
        $this->assertSame('300.00', $row[3]); // 1000 × 30%, признано в текущем месяце
        $this->assertSame('0.00', $row[4]);
        $this->assertSame('300.00', $row[5]);
        $this->assertSame('100.00', $row[6]); // аванс 50 не зачтён — не входит
        $this->assertSame('200.00', $row[7]);
    }

    /** @test */
    public function payouts_export_row_formats_amount_date_and_salary_type_label(): void
    {
        $teacher = Teacher::create(['name' => 'Екатерина']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Санскрит I']);

        $payout = $teacher->payouts()->create([
            'amount' => 16008.5,
            'paid_at' => '2026-05-10',
            'period_month' => '2026-05',
            'course_id' => $course->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'comment' => 'за блок 5',
        ]);

        $row = $this->exportRow(TeacherPayoutsExporter::class, $payout);

        // id, teacher.name, amount, paid_at, period_month, course.title, salary_type, salary_value, comment
        $this->assertSame((string) $payout->id, (string) $row[0]);
        $this->assertSame('Екатерина', $row[1]);
        $this->assertSame('16008.50', $row[2]);
        $this->assertSame('10.05.2026', $row[3]);
        $this->assertSame('2026-05', $row[4]);
        $this->assertSame('Санскрит I', $row[5]);
        $this->assertSame('% от выручки', $row[6]);
        $this->assertSame('30.00', (string) $row[7]);
        $this->assertSame('за блок 5', $row[8]);
    }
}
