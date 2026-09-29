<?php

declare(strict_types=1);

namespace Tests\Feature\Debts;

use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Services\DebtPaymentResolver;
use App\Services\StudentDebtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Бронь / пробное / возврат без границ блоков не должны читаться как
 * «оплачен весь курс» при расчёте долга (гр.60, 28-09-2026: студентка с
 * бронью не видела «Оплатить блок 3» в кабинете и не попадала в «Должники»).
 *
 * Флаг features.debt_strict_block_coverage (дефолт OFF): при выключенном —
 * прежнее поведение, это тоже пиннится.
 */
class StrictBlockCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-28 12:00:00');
        Debtors::flushPairCaches();

        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'gram-gr60']);
        $this->group = Group::create(['name' => 'гр.60']);
        $this->course->groups()->attach($this->group->id);

        // Блок 3 идёт сейчас, 1–2 прошли, 4 впереди.
        $dates = [
            1 => ['2026-08-03', '2026-08-24'],
            2 => ['2026-08-31', '2026-09-06'],
            3 => ['2026-09-08', '2026-09-29'],
            4 => ['2026-10-06', '2026-10-27'],
        ];
        foreach ($dates as $n => [$from, $to]) {
            CourseBlock::factory()->for($this->course)
                ->withDates(Carbon::parse($from), Carbon::parse($to))
                ->create(['number' => $n]);
        }
    }

    protected function tearDown(): void
    {
        Debtors::flushPairCaches();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function strict(bool $on = true): void
    {
        config(['features.debt_strict_block_coverage' => $on]);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->groups()->attach($this->group->id);

        return $user;
    }

    private function pay(User $user, string $tariff, float $amount, ?int $start, ?int $end): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => $amount,
            'tariff' => $tariff,
            'status' => 'paid',
            'start_block' => $start,
            'end_block' => $end,
        ]));
    }

    /** Студентка гр.60: бронь → блок 1 → блок 2, блок 3 не оплачен. */
    private function depositStudent(): User
    {
        $user = $this->student();
        $this->pay($user, 'deposit', 6000, null, null);
        $this->pay($user, 'block_1', 2000, 1, 1);
        $this->pay($user, 'block_2', 8000, 2, 2);

        return $user;
    }

    /** @return list<int>|null  блоки долга по курсу в кабинете (null — долга нет) */
    private function cabinetDebt(User $user): ?array
    {
        $debt = app(StudentDebtsService::class)->forUser($user)->firstWhere('course_id', $this->course->id);

        return $debt?->debt_block_numbers;
    }

    /** @return list<int> */
    private function debtorsReportPairs(): array
    {
        return app(DebtorsReport::class)->query()
            ->where('d.course_id', $this->course->id)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_flag_off_keeps_the_old_behaviour(): void
    {
        $this->strict(false);
        $user = $this->depositStudent();

        $this->assertNull($this->cabinetDebt($user), 'Флаг OFF: бронь по-прежнему прячет долг (прежнее поведение).');
        $this->assertNotContains($user->id, $this->debtorsReportPairs());
        $this->assertSame([], Debtors::debtBlocks($user->id, $this->course->id, 3));
    }

    public function test_deposit_no_longer_hides_the_current_block_debt_in_the_cabinet(): void
    {
        $this->strict();
        $user = $this->depositStudent();

        $this->assertSame([3], $this->cabinetDebt($user));
    }

    public function test_deposit_student_appears_in_the_admin_debtors_list_and_card(): void
    {
        $this->strict();
        $user = $this->depositStudent();

        $this->assertContains($user->id, $this->debtorsReportPairs());
        $this->assertSame([3], Debtors::debtBlocks($user->id, $this->course->id, 3));
    }

    public function test_trial_without_blocks_does_not_cover_the_course(): void
    {
        $this->strict();
        $user = $this->student();
        $this->pay($user, 'trial', 500, null, null);
        $this->pay($user, 'block_1', 8000, 1, 1);

        $this->assertSame([2, 3], $this->cabinetDebt($user));
    }

    public function test_refund_row_does_not_cover_and_alone_does_not_make_a_debtor(): void
    {
        $this->strict();

        // Только возврат, ни одной покупки: не должник (ложный долг за все блоки — хуже бага).
        $refundOnly = $this->student();
        $this->pay($refundOnly, 'Расход', -8000, null, null);
        $this->assertNull($this->cabinetDebt($refundOnly));

        // Покупка + возврат без границ: возврат блок не покрывает.
        $buyer = $this->student();
        $this->pay($buyer, 'block_1', 8000, 1, 1);
        $this->pay($buyer, 'Расход', -500, null, null);
        $this->assertSame([2, 3], $this->cabinetDebt($buyer));
    }

    public function test_full_course_payment_still_covers_everything(): void
    {
        $this->strict();
        $user = $this->student();
        $this->pay($user, 'full', 40000, null, null);

        $this->assertNull($this->cabinetDebt($user));
        $this->assertNotContains($user->id, $this->debtorsReportPairs());
        $this->assertSame([], Debtors::debtBlocks($user->id, $this->course->id, 3));
    }

    public function test_paid_block_is_still_covered(): void
    {
        $this->strict();
        $user = $this->depositStudent();
        $this->pay($user, 'block_3', 8000, 3, 3);

        $this->assertNull($this->cabinetDebt($user));
        $this->assertNotContains($user->id, $this->debtorsReportPairs());
    }

    public function test_unpaid_blocks_and_paid_until_ignore_the_deposit_but_keep_its_money(): void
    {
        $this->strict();
        $user = $this->depositStudent();
        $service = app(StudentDebtsService::class);

        $this->assertSame([3, 4], $service->unpaidBlockNumbers($user, $this->course->id));

        $until = $service->paidUntilForUser($user, [$this->course->id])->get($this->course->id);
        $this->assertNotNull($until);
        $this->assertSame(2, (int) $until->block->number, 'Оплачено до блока 2, а не «до конца курса».');
        $this->assertSame(3, (int) $until->next_block->number);
        $this->assertSame(16000.0, $until->amount_paid, 'Внесённая сумма по-прежнему включает бронь.');
    }

    /**
     * Переведённая из распавшейся гр.60 в гр.61 с блока 3: бронь осталась в
     * гр.60, платить блок 3 она должна в гр.61. Админ ставит «Блок выхода» 2
     * в гр.60 и «Блок входа» 3 в гр.61 — долг и кнопка оплаты только в гр.61.
     */
    public function test_transferred_student_owes_the_block_in_the_new_course_not_where_the_deposit_is(): void
    {
        $this->strict();
        $user = $this->depositStudent();

        $newCourse = Course::factory()->create(['is_active' => true, 'slug' => 'gram-gr61']);
        $newGroup = Group::create(['name' => 'гр.61']);
        $newCourse->groups()->attach($newGroup->id);
        $user->groups()->attach($newGroup->id);
        CourseBlock::factory()->for($newCourse)
            ->withDates(Carbon::parse('2026-09-21'), Carbon::parse('2026-10-12'))
            ->create(['number' => 3]);
        foreach ([1, 2] as $n) {
            CourseBlock::factory()->for($newCourse)
                ->withDates(Carbon::parse('2026-07-27')->addWeeks(4 * ($n - 1)), Carbon::parse('2026-08-17')->addWeeks(4 * ($n - 1)))
                ->create(['number' => $n]);
        }

        $user->courses()->attach($this->course->id, ['status' => 'Записался', 'left_after_block' => 2]);
        $user->courses()->attach($newCourse->id, ['status' => 'Записался', 'joined_at_block' => 3]);

        $debts = app(StudentDebtsService::class)->forUser($user)->keyBy('course_id');

        $this->assertFalse($debts->has($this->course->id), 'В гр.60 после «Блока выхода» 2 долга нет.');
        $this->assertTrue($debts->has($newCourse->id), 'Долг появляется в гр.61, хотя платежей там ещё нет.');
        $this->assertSame([3], $debts[$newCourse->id]->debt_block_numbers, 'Блоки 1–2 гр.61 до «Блока входа» не начисляются.');

        // Кабинет строит кнопку «Оплатить блок №3» по тарифу нового курса.
        $tariff = Tariff::factory()->block(3)->create(['course_id' => $newCourse->id, 'price' => 8000, 'is_active' => true]);
        $options = app(DebtPaymentResolver::class)->optionsFor($debts[$newCourse->id], $user);
        $this->assertSame('tariff', $options['type']);
        $this->assertSame(3, (int) $options['blocks'][0]['number']);
        $this->assertStringContainsString((string) $tariff->id, $options['blocks'][0]['url']);

        // Одно членство в группе без «Блока входа» долга не создаёт (прежнее правило).
        $other = $this->student();
        $other->groups()->attach($newGroup->id);
        $this->assertFalse(app(StudentDebtsService::class)->forUser($other)->contains('course_id', $newCourse->id));

        // Флаг OFF — «Блок входа» кандидатом курс не делает.
        $this->strict(false);
        $this->assertFalse(app(StudentDebtsService::class)->forUser($user)->contains('course_id', $newCourse->id));
    }

    public function test_non_covering_tariff_list_is_empty_while_the_flag_is_off(): void
    {
        $this->strict(false);
        $this->assertSame([], DebtorsReport::nonCoveringTariffs());

        $this->strict();
        $this->assertSame(['Расход', 'salary_payout', 'deposit', 'trial'], DebtorsReport::nonCoveringTariffs());
    }
}
