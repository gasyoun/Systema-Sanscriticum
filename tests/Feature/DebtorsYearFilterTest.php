<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\DebtorsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Год-линза раздела «Должники»: год = год даты блока (course_blocks.starts_at).
 * Пустой набор годов = все годы (поведение по умолчанию).
 */
class DebtorsYearFilterTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        // Статические кэши Debtors рассчитаны на свежий процесс на каждый
        // HTTP-запрос; в одном тест-процессе id переиспользуются → сбрасываем.
        foreach ([
            'paymentCache', 'courseBlockNumbersCache', 'blockYearsCache',
            'userCoursePaymentsCache', 'debtAmountCache', 'joinedAtBlockCache',
        ] as $prop) {
            $ref = new \ReflectionProperty(Debtors::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, []);
        }

        // Фиксируем «сейчас» в июне 2026, чтобы блок №5 был текущим.
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

        $this->course = Course::factory()->create(['is_active' => true]);

        // 2025: №1–3 (все в прошлом).
        $this->block(1, '2025-01-01', '2025-01-31');
        $this->block(2, '2025-02-01', '2025-02-28');
        $this->block(3, '2025-03-01', '2025-03-31');
        // 2026: №4 прошёл, №5 текущий, №6 в будущем.
        $this->block(4, '2026-01-01', '2026-01-31');
        $this->block(5, '2026-06-01', '2026-06-30');
        $this->block(6, '2026-09-01', '2026-09-30');

        // Block-тарифы для точного подсчёта суммы (4800 за блок).
        foreach ([4, 5] as $n) {
            Tariff::factory()->for($this->course)->block($n)->create();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function block(int $number, string $starts, string $ends): void
    {
        CourseBlock::factory()->for($this->course)
            ->withDates(Carbon::parse($starts), Carbon::parse($ends))
            ->create(['number' => $number]);
    }

    private function paid(User $user, int $start, int $end): void
    {
        Payment::withoutEvents(function () use ($user, $start, $end): void {
            Payment::create([
                'user_id' => $user->id,
                'course_id' => $this->course->id,
                'tariff' => 'block_'.$start,
                'status' => 'paid',
                'amount' => 4800,
                'start_block' => $start,
                'end_block' => $end,
                'is_conditional' => false,
            ]);
        });
    }

    /**
     * @param  list<int>  $years
     * @return list<int> id студентов в выборке должников
     */
    private function debtorIds(array $years): array
    {
        return app(DebtorsReport::class)->forYears($years)->query()
            ->get()->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** @test */
    public function reference_block_follows_selected_year(): void
    {
        $report = app(DebtorsReport::class);

        // Без линзы reference = текущий блок (№5, июнь 2026).
        $this->assertSame(5, (int) $report->forYears([])->referenceBlocks()->get($this->course->id)->number);
        // Линза 2025 → последний начавшийся блок 2025 = №3.
        $this->assertSame(3, (int) app(DebtorsReport::class)->forYears([2025])->referenceBlocks()->get($this->course->id)->number);
        // Линза 2026 → последний начавшийся блок 2026 = №5 (№6 ещё в будущем).
        $this->assertSame(5, (int) app(DebtorsReport::class)->forYears([2026])->referenceBlocks()->get($this->course->id)->number);
    }

    /** @test */
    public function student_who_paid_full_2025_is_not_a_2025_debtor_but_is_a_2026_debtor(): void
    {
        $user = User::factory()->create();
        $this->paid($user, 1, 3); // закрыл весь 2025

        // 2025 покрыт → из списка за 2025 исчезает.
        $this->assertNotContains($user->id, $this->debtorIds([2025]));
        // За 2026 — должник (№4,5 не оплачены).
        $this->assertContains($user->id, $this->debtorIds([2026]));
        // Без линзы — тоже должник.
        $this->assertContains($user->id, $this->debtorIds([]));

        // Состав долговых блоков по линзам.
        $this->assertSame([4, 5], Debtors::debtBlocks($user->id, $this->course->id, 5, [2026]));
        $this->assertSame([], Debtors::debtBlocks($user->id, $this->course->id, 3, [2025]));
        $this->assertSame([4, 5], Debtors::debtBlocks($user->id, $this->course->id, 5, []));
    }

    /** @test */
    public function debt_amount_is_scoped_to_selected_year(): void
    {
        $user = User::factory()->create();
        $this->paid($user, 1, 3);

        $report = app(DebtorsReport::class);
        $blocks2026 = Debtors::debtBlocks($user->id, $this->course->id, 5, [2026]);
        $info = $report->computeDebtAmount($user, $this->course->id, $blocks2026);

        // №4 и №5 по 4800 = 9600.
        $this->assertSame(9600.0, $info['amount']);
        $this->assertSame(0, $info['missing_tariffs']);
    }

    /** @test */
    public function student_with_2025_gap_appears_under_2025_lens(): void
    {
        $user = User::factory()->create();
        $this->paid($user, 1, 1); // оплачен только №1, №2/№3 — долг 2025

        $this->assertContains($user->id, $this->debtorIds([2025]));
        $this->assertSame([2, 3], Debtors::debtBlocks($user->id, $this->course->id, 3, [2025]));
    }
}
