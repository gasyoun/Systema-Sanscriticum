<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReactivationTemplate;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ReactivationReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Отчёт реактивации = тонкий слой над DebtorsReport (тот же сегмент «не продлил
 * + без оплат» и те же исключения) + подсказка win/loss-шаблона `/реактивация-*`.
 */
class ReactivationReportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        // «Сейчас» = июнь 2026, блок №5 — текущий (как в DebtorsYearFilterTest).
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

        $this->course = Course::factory()->create(['is_active' => true]);

        $this->block(1, '2026-01-01', '2026-01-31');
        $this->block(2, '2026-02-01', '2026-02-28');
        $this->block(3, '2026-03-01', '2026-03-31');
        $this->block(4, '2026-04-01', '2026-04-30');
        $this->block(5, '2026-06-01', '2026-06-30');

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

    /** Должник «без оплат»: в группе курса, реального платежа нет. */
    private function makeNoPaymentDebtor(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $group = Group::create(['name' => 'Поток '.fake()->unique()->numberBetween(1, 9999)]);
        $this->course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        return $user;
    }

    private function report(): ReactivationReport
    {
        return app(ReactivationReport::class);
    }

    /** @test */
    public function template_routing_is_grounded_in_winloss(): void
    {
        // Платил раньше, не продлил → тёплый возврат бывшего ученика.
        $this->assertSame(
            ReactivationTemplate::Student,
            ReactivationTemplate::suggestFor('not_renewed', false),
        );
        // В группе, ни одной оплаты → барьер цены (рассрочка/льгота, лифт +8/+9).
        $this->assertSame(
            ReactivationTemplate::Price,
            ReactivationTemplate::suggestFor('no_payment', false),
        );
        // Есть рассрочка → повод по цене независимо от типа долга.
        $this->assertSame(
            ReactivationTemplate::Price,
            ReactivationTemplate::suggestFor('not_renewed', true),
        );
        // Неизвестный тип → общий тёплый повод.
        $this->assertSame(
            ReactivationTemplate::General,
            ReactivationTemplate::suggestFor('whatever', false),
        );
    }

    /** @test */
    public function not_renewed_candidate_is_suggested_the_student_template(): void
    {
        $user = User::factory()->create(['telegram_id' => '700001']);
        $this->paid($user, 1, 3); // платил блоки 1–3, не продлил на 4/5

        $report = $this->report();
        $row = $report->query()->get()->firstWhere('id', $user->id);

        $this->assertNotNull($row, 'не продливший должен попасть в отчёт реактивации');
        $this->assertSame('not_renewed', $row->debt_type);
        $this->assertSame(ReactivationTemplate::Student, $report->suggestTemplate($row));
    }

    /** @test */
    public function no_payment_candidate_is_suggested_the_price_template(): void
    {
        $user = $this->makeNoPaymentDebtor(['telegram_id' => '700002']);

        $report = $this->report();
        $row = $report->query()->get()->firstWhere('id', $user->id);

        $this->assertNotNull($row, 'без-оплат должник должен попасть в отчёт реактивации');
        $this->assertSame('no_payment', $row->debt_type);
        $this->assertSame(ReactivationTemplate::Price, $report->suggestTemplate($row));
    }

    /** @test */
    public function summary_counts_candidates_by_reason(): void
    {
        $renewed = User::factory()->create();
        $this->paid($renewed, 1, 3); // не продлил
        $this->makeNoPaymentDebtor(); // без оплат

        $page = new \App\Filament\Pages\Reactivation;
        $summary = $page->summary();

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['not_renewed']);
        $this->assertSame(1, $summary['no_payment']);
    }

    /** @test */
    public function page_renders_for_admin_with_candidate_rows(): void
    {
        $notRenewed = User::factory()->create(['name' => 'Не Продлил', 'telegram_id' => '700003']);
        $this->paid($notRenewed, 1, 3);
        $this->makeNoPaymentDebtor(['name' => 'Без Оплат']);

        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Pages\Reactivation::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$notRenewed]);
    }
}
