<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherSalaries;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\MutualSettlement;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\MutualSettlementService;
use App\Services\TeacherSalaryService;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Взаимозачёт «преподаватель-ученик» (H1730, волна B).
 *
 * Покрывает таблицу приёмки волны B из
 * docs/VERIFICATION_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md.
 * Всё на фикстурах: боевых данных локально нет и ни один критерий на них не
 * опирается.
 */
class MutualSettlementTest extends TestCase
{
    use RefreshDatabase;

    private MutualSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MutualSettlementService::class);
    }

    /**
     * Один человек в двух ипостасях: User с проставленным teacher_id + курс,
     * который он ведёт по проценту.
     *
     * @return array{0: User, 1: Teacher, 2: Course}
     */
    private function person(float $percent = 60): array
    {
        $teacher = Teacher::create(['name' => 'Ольга Литвиненко']);
        $user = User::factory()->create(['name' => 'Ольга Литвиненко', 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => $percent,
        ]);

        return [$user, $teacher->fresh('courses'), $course];
    }

    /**
     * Курс ЧУЖОГО преподавателя — за него она платит как ученик.
     *
     * Это не косметика фикстуры: если платить за собственный курс, оплата
     * попадает в его выручку и возвращается ей же начислением (60 % от своих
     * денег), и сверка перестаёт мерить то, ради чего заведена. Реальный
     * случай — она платит Гасунсу, а получает за свой курс.
     */
    private function otherTeachersCourse(): Course
    {
        $other = Teacher::create(['name' => 'Гасунс']);

        return Course::factory()->create([
            'teacher_id' => $other->id,
            'salary_type' => 'percent',
            'salary_value' => 50,
        ]);
    }

    /** Платёж напрямую в БД, без обсёрвера (доступ/прана в сетапе не нужны). */
    private function pay(Course $course, array $attrs): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create(array_merge([
            'course_id' => $course->id,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'tariff' => 'full',
        ], $attrs)));
    }

    // ==========================================================
    // B1 — сервис сверки
    // ==========================================================

    /** @test */
    public function tuition_excludes_expense_and_salary_payout_tariffs(): void
    {
        [$user, , $course] = $this->person();

        // Реальная оплата обучения — входит.
        $this->pay($course, ['user_id' => $user->id, 'amount' => 30000, 'tariff' => 'full']);
        // Возврат (Расход) — не оплата обучения.
        $this->pay($course, ['user_id' => $user->id, 'amount' => -5000, 'tariff' => 'Расход']);
        // Зеркало выплаты ЗП — тем более не оплата обучения.
        $this->pay($course, ['user_id' => $user->id, 'amount' => 40000, 'tariff' => 'salary_payout']);

        $tuition = $this->service->tuitionFor($user);

        $this->assertSame(30000.0, $tuition['total']);
        $this->assertCount(1, $tuition['lines']);
        $this->assertSame('full', $tuition['lines'][0]['tariff']);
    }

    /** @test */
    public function tuition_uses_the_same_excluded_tariff_list_as_the_salary_service(): void
    {
        // Не своя копия списка: разъехавшись, две копии дали бы две «правды».
        $this->assertSame(
            TeacherSalaryService::NON_REVENUE_TARIFFS,
            MutualSettlementService::nonTuitionTariffs(),
        );
    }

    /** @test */
    public function tuition_ignores_payments_that_went_straight_to_the_teacher(): void
    {
        [$user, $teacher, $course] = $this->person();

        $this->pay($course, ['user_id' => $user->id, 'amount' => 30000]);
        // Деньги мимо кассы школы — обязательства перед школой не образуют.
        $this->pay($course, [
            'user_id' => $user->id, 'amount' => 7000,
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $teacher->id,
        ]);

        $this->assertSame(30000.0, $this->service->tuitionFor($user)['total']);
    }

    /** @test */
    public function salary_figure_matches_the_salary_page_for_the_same_period(): void
    {
        [$user, $teacher, $course] = $this->person(60);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $fromService = $this->service->salaryFor($teacher)['total'];
        $fromSalaryPage = app(TeacherSalaryService::class)->totalForTeacher($teacher);

        // Второго расчёта ЗП не появилось — цифра ровно та же.
        $this->assertSame($fromSalaryPage, $fromService);
        $this->assertSame(60000.0, $fromService);

        // И она же попадает в preview.
        $this->assertSame(60000.0, $this->service->preview($user)['salary_amount']);
    }

    /** @test */
    public function same_person_guard_fails_when_teacher_id_is_missing(): void
    {
        $user = User::factory()->create(['teacher_id' => null]);

        $this->expectException(\DomainException::class);
        $this->service->preview($user);
    }

    /**
     * Вторая половина инварианта «один человек» держится внешним ключом:
     * повисший users.teacher_id база просто не примет. Ветка-страховка в
     * сервисе остаётся, но полагаться нужно на этот запрет.
     *
     * @test
     */
    public function database_refuses_a_dangling_teacher_id(): void
    {
        $this->expectException(QueryException::class);

        User::factory()->create(['teacher_id' => 999999]);
    }

    /** @test */
    public function settle_returns_the_smaller_sum_and_the_right_direction(): void
    {
        // Начисление больше оплат — остаток доплачивает школа.
        $s = MutualSettlementService::settle(tuition: 30000, salary: 50000);
        $this->assertSame(30000.0, $s['offset_amount']);
        $this->assertSame(20000.0, $s['net_amount']);
        $this->assertSame(MutualSettlement::DIRECTION_SCHOOL_PAYS, $s['net_direction']);

        // Оплаты больше начисления — доплачивает человек.
        $s = MutualSettlementService::settle(tuition: 50000, salary: 30000);
        $this->assertSame(30000.0, $s['offset_amount']);
        $this->assertSame(20000.0, $s['net_amount']);
        $this->assertSame(MutualSettlement::DIRECTION_STUDENT_PAYS, $s['net_direction']);

        // Сошлись.
        $s = MutualSettlementService::settle(tuition: 40000, salary: 40000);
        $this->assertSame(40000.0, $s['offset_amount']);
        $this->assertSame(0.0, $s['net_amount']);
        $this->assertSame(MutualSettlement::DIRECTION_ZERO, $s['net_direction']);
    }

    // ==========================================================
    // B2 — разовая сверка командой
    // ==========================================================

    /** @test */
    public function preview_command_prints_both_sums_and_writes_nothing(): void
    {
        [$user, , $course] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 30000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $this->artisan('settlement:preview', ['user' => (string) $user->id])
            ->expectsOutputToContain('Оплачено как ученик')
            ->expectsOutputToContain('Начислено как преподавателю')
            ->expectsOutputToContain('Ничего не записано')
            ->assertSuccessful();

        // Только чтение: ни акта, ни выплаты (скоуп по user — не глобальный count,
        // иначе чужой мусор из MySQL-job соседей ломает ассерт).
        $this->assertSame(0, MutualSettlement::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, TeacherPayout::query()->where('teacher_id', $user->teacher_id)->count());
    }

    /** @test */
    public function preview_command_fails_loudly_when_the_person_is_not_a_teacher(): void
    {
        $user = User::factory()->create(['teacher_id' => null]);

        $this->artisan('settlement:preview', ['user' => (string) $user->id])->assertFailed();
    }

    // ==========================================================
    // B3 — модель акта и фиксация
    // ==========================================================

    /** @test */
    public function fixing_freezes_the_numbers_so_a_late_payment_cannot_move_them(): void
    {
        [$user, , $course] = $this->person(60);
        $foreign = $this->otherTeachersCourse();
        // Платит за чужой курс — 30000; зарабатывает на своём — 60 % от 100000.
        $this->pay($foreign, ['user_id' => $user->id, 'amount' => 30000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $act = $this->service->fix($user);
        $this->assertSame(MutualSettlement::STATUS_FIXED, $act->status);
        $this->assertSame(30000.0, (float) $act->tuition_amount);
        $this->assertSame(60000.0, (float) $act->salary_amount);
        $this->assertSame(30000.0, (float) $act->offset_amount);

        // Поздняя оплата задним числом.
        $this->pay($foreign, ['user_id' => $user->id, 'amount' => 15000]);

        // Живая сверка сдвинулась…
        $this->assertSame(45000.0, $this->service->preview($user)['tuition_amount']);
        // …а подписанная цифра — нет. Иначе «зафиксировали» ничего не значит.
        $this->assertSame(30000.0, (float) $act->fresh()->tuition_amount);
    }

    /** @test */
    public function refixing_supersedes_the_previous_act_and_keeps_the_history(): void
    {
        [$user] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 30000]);

        $first = $this->service->fix($user);
        $second = $this->service->fix($user);

        $this->assertSame(MutualSettlement::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame(MutualSettlement::STATUS_FIXED, $second->fresh()->status);
        // История полная — прежняя строка не удалена и не переписана.
        $this->assertSame(2, MutualSettlement::query()->where('user_id', $user->id)->count());
        $this->assertSame(30000.0, (float) $first->fresh()->tuition_amount);
    }

    /** @test */
    public function fixing_stores_the_breakdown_of_both_sums_at_the_moment_of_fixing(): void
    {
        [$user, , $course] = $this->person(60);
        $foreign = $this->otherTeachersCourse();
        $this->pay($foreign, ['user_id' => $user->id, 'amount' => 30000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $act = $this->service->fix($user, null, null, null, null, null, 'сверено с бухгалтером');

        $this->assertSame('сверено с бухгалтером', $act->note);
        $this->assertCount(1, $act->breakdown['tuition_lines']);
        $this->assertCount(1, $act->breakdown['salary_lines']);
        // JSON-круг возвращает целые как int — сравниваем по значению.
        $this->assertEquals(30000.0, $act->breakdown['fixed_preview']['offset_amount']);
    }

    // ==========================================================
    // B5 — зачёт до выплаты
    // ==========================================================

    /**
     * Выплата создаётся НЕТТО: зачёт вычтен, а в breakdown лежит и id акта, и
     * сумма зачёта. Сам акт помечается израсходованным.
     *
     * @test
     */
    public function block_payout_is_created_net_of_a_fixed_settlement(): void
    {
        [$user, $teacher, $course] = $this->person(60);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'starts_at' => now()->subMonth(), 'ends_at' => now()]);
        $foreign = $this->otherTeachersCourse();
        $this->pay($foreign, ['user_id' => $user->id, 'amount' => 20000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $act = $this->service->fix($user);
        $this->assertSame(20000.0, (float) $act->offset_amount);

        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'block_number' => 1,
                'salary_type' => 'percent',
                'base_revenue' => 100000,
                'teacher_percent' => 60,
                'coefficient' => 100,
                'mutual_settlement_id' => $act->id,
                'post_to_finance' => false,
                'email_report' => false,
            ]);

        $payout = TeacherPayout::query()->latest('id')->first();
        $this->assertNotNull($payout);

        // 100000 × 60% = 60000, минус зачёт 20000 = 40000.
        $this->assertSame(40000.0, (float) $payout->amount);
        $this->assertSame($act->id, $payout->breakdown['mutual_settlement_id']);
        $this->assertEquals(20000.0, $payout->breakdown['mutual_settlement_offset']);

        // Акт израсходован именно этой выплатой.
        $this->assertSame((int) $payout->id, (int) $act->fresh()->payout_id);
    }

    /** @test */
    public function a_settlement_cannot_be_offset_twice(): void
    {
        [$user, $teacher, $course] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 20000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $act = $this->service->fix($user);
        $payoutA = $teacher->payouts()->create(['amount' => 1000, 'paid_at' => now()->toDateString()]);
        $payoutB = $teacher->payouts()->create(['amount' => 1000, 'paid_at' => now()->toDateString()]);

        // Первый зачёт проходит…
        $this->assertTrue($this->service->consume($act, (int) $payoutA->id));
        // …повторный отвергается на уровне данных, и акт остаётся за первой выплатой.
        $this->assertFalse($this->service->consume($act->fresh(), (int) $payoutB->id));
        $this->assertSame((int) $payoutA->id, (int) $act->fresh()->payout_id);
    }

    /** @test */
    public function fixing_a_scope_already_consumed_by_a_payout_is_rejected(): void
    {
        [$user, $teacher] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 20000]);

        $act = $this->service->fix($user);
        $payout = $teacher->payouts()->create(['amount' => 1000, 'paid_at' => now()->toDateString()]);
        $this->assertTrue($this->service->consume($act, (int) $payout->id));

        try {
            $this->service->fix($user);
            $this->fail('A consumed settlement scope must not be revised.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('уже использован', $exception->getMessage());
        }

        $this->assertSame(1, MutualSettlement::query()->where('user_id', $user->id)->count());
        $this->assertSame(MutualSettlement::STATUS_FIXED, $act->fresh()->status);
    }

    /** @test */
    public function a_consumed_scope_does_not_block_a_different_period(): void
    {
        [$user, $teacher] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 20000]);

        $july = $this->service->fix($user, null, null, '2026-07-01', '2026-07-31');
        $payout = $teacher->payouts()->create(['amount' => 1000, 'paid_at' => now()->toDateString()]);
        $this->assertTrue($this->service->consume($july, (int) $payout->id));

        $august = $this->service->fix($user, null, null, '2026-08-01', '2026-08-31');

        $this->assertSame(MutualSettlement::STATUS_FIXED, $august->status);
        $this->assertSame(2, MutualSettlement::query()->where('user_id', $user->id)->count());
    }

    /** @test */
    public function a_consumed_settlement_is_no_longer_offered_for_offset(): void
    {
        [$user, $teacher, $course] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 20000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $act = $this->service->fix($user);
        $this->assertCount(1, $this->service->availableForTeacher($teacher));

        $payout = $teacher->payouts()->create(['amount' => 1000, 'paid_at' => now()->toDateString()]);
        $this->service->consume($act, (int) $payout->id);

        $this->assertCount(0, $this->service->availableForTeacher($teacher));
    }

    /** @test */
    public function a_superseded_settlement_is_not_offered_for_offset(): void
    {
        [$user, $teacher, $course] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 20000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $this->service->fix($user);
        $this->service->fix($user);

        // Заменённый акт зачесть нельзя — в кандидатах только последний.
        $available = $this->service->availableForTeacher($teacher);
        $this->assertCount(1, $available);
        $this->assertSame(
            (int) MutualSettlement::query()
                ->where('user_id', $user->id)
                ->fixed()
                ->value('id'),
            $available[0]['settlement_id'],
        );
    }

    /**
     * Регрессия: у преподавателя БЕЗ акта расчёт выплаты не изменился ни на
     * копейку — правка B5 аддитивна.
     *
     * @test
     */
    public function payout_without_a_settlement_is_unchanged(): void
    {
        $teacher = Teacher::create(['name' => 'Преподаватель без взаимозачёта']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 60,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'starts_at' => now()->subMonth(), 'ends_at' => now()]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'block_number' => 1,
                'salary_type' => 'percent',
                'base_revenue' => 100000,
                'teacher_percent' => 60,
                'coefficient' => 100,
                'post_to_finance' => false,
                'email_report' => false,
            ]);

        $payout = TeacherPayout::query()->latest('id')->first();
        $this->assertNotNull($payout);
        $this->assertSame(60000.0, (float) $payout->amount);
        $this->assertNull($payout->breakdown['mutual_settlement_id']);
        $this->assertEquals(0.0, $payout->breakdown['mutual_settlement_offset']);
    }

    // ==========================================================
    // B6 — триггер пересмотра
    // ==========================================================

    /** @test */
    public function needs_review_fires_when_accrual_outweighs_payments(): void
    {
        [$user, , $course] = $this->person(60);
        // Оплатила 20000, начислено 60000 → начисление перевешивает.
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 20000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $act = $this->service->fix($user);

        $this->assertTrue($this->service->needsReview($act));
        $this->assertSame(1, $this->service->needsReviewCount());
    }

    /** @test */
    public function needs_review_stays_quiet_while_payments_outweigh_accrual(): void
    {
        [$user, , $course] = $this->person(60);
        // Оплатила 100000 за чужой курс, начислено 0 → пересматривать нечего.
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 100000]);

        $act = $this->service->fix($user);

        $this->assertFalse($this->service->needsReview($act));
        $this->assertSame(0, $this->service->needsReviewCount());
    }

    /** @test */
    public function needs_review_ignores_acts_that_are_not_fixed(): void
    {
        [$user, , $course] = $this->person(60);
        $this->pay($this->otherTeachersCourse(), ['user_id' => $user->id, 'amount' => 20000]);
        $student = User::factory()->create();
        $this->pay($course, ['user_id' => $student->id, 'amount' => 100000]);

        $act = $this->service->fix($user);
        $act->update(['status' => MutualSettlement::STATUS_SUPERSEDED]);

        $this->assertFalse($this->service->needsReview($act->fresh()));
    }

    // ==========================================================
    // Гейт страницы
    // ==========================================================

    /** @test */
    public function settlements_page_renders_for_accountant(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $this->actingAs($accountant)->get('/admin/mutual-settlements')->assertSuccessful();
    }

    /** @test */
    public function settlements_page_is_closed_for_a_plain_admin(): void
    {
        // Гейт тот же, что у страницы «Зарплаты»: обычный admin туда не проходит.
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $this->actingAs($admin)->get('/admin/mutual-settlements')->assertForbidden();
    }
}
