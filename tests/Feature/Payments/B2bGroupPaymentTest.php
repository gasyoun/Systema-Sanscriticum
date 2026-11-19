<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Http\Controllers\StudentController;
use App\Jobs\SendGroupChatInviteJob;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Access\LessonGate;
use App\Services\CuratorNotifier;
use App\Services\DebtorsReport;
use App\Services\Payments\B2bGroupEntitlement;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * H8245 — B2B-оплата «за группу целиком» (Адара → курс 463 «Грамматика по
 * Кочергиной», group 157).
 *
 * Контракт money-контура:
 *  1. b2b_group-платёж — чисто денежная строка: плательщику НЕ выдаётся доступ,
 *     письма/прана/Telegram не срабатывают; куратор уведомляется.
 *  2. Ученики группы с оплаченным b2b_group-платежом открывают уроки ВИРТУАЛЬНЫМИ
 *     ключами (флаг features.b2b_group_payment); при OFF поведение прежнее.
 *  3. Выручка: курсовой accrual считает b2b-платёж как обычный (30% ЗП),
 *     блок-калькулятор атрибутирует его группе ТОЛЬКО под флагом.
 *  4. Откат b2b-платежа НЕ детачит учеников группы, но ключи закрываются;
 *     должники не считают покрытых b2b-учеников (под флагом).
 */
class B2bGroupPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private User $student;

    private User $outsider;

    private User $payer;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Moscow']);

        $this->teacher = Teacher::create(['name' => 'Преподаватель гр.64']);
        $this->course = Course::factory()->create([
            'teacher_id' => $this->teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $this->course->id, 'number' => 1, 'is_active' => true, 'starts_at' => '2026-11-01']);
        CourseBlock::create(['course_id' => $this->course->id, 'number' => 2, 'is_active' => true, 'starts_at' => '2026-12-01']);

        $this->group = Group::factory()->create();
        $this->course->groups()->attach($this->group->id);

        Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'block_number' => 1,
            'is_free' => false,
        ]);

        $this->student = User::factory()->create();
        $this->outsider = User::factory()->create();
        $this->payer = User::factory()->create();

        $this->student->groups()->attach($this->group->id);
    }

    /**
     * Платёж юрлица за группу. $quiet=true — создание мимо модельных событий
     * (для тестов выручки/отчётов, где paid-цепочка не предмет проверки).
     */
    private function payB2bGroup(bool $quiet = true, array $attrs = []): Payment
    {
        $payload = array_merge([
            'user_id' => $this->payer->id,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'tariff' => Payment::TARIFF_B2B_GROUP,
            'amount' => 30000,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'payment_method' => 'cash',
            'payer_note' => 'Оплата за группу (студия Адара)',
        ], $attrs);

        if ($quiet) {
            return Payment::withoutEvents(fn () => Payment::create($payload))->fresh();
        }

        return Payment::create($payload)->fresh();
    }

    private function lesson(): Lesson
    {
        return Lesson::query()->where('course_id', $this->course->id)->firstOrFail();
    }

    /** Дефолт OFF: b2b_group-платёж не открывает никому доступ — контур прежний. */
    public function test_flag_off_group_students_stay_locked(): void
    {
        $this->assertFalse(B2bGroupEntitlement::enabled());
        $this->payB2bGroup();

        $gate = app(LessonGate::class);
        $this->assertFalse($gate->canWatch($this->student, $this->lesson()));
        $this->assertSame(
            [],
            StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug),
        );
    }

    /** Флаг ON: ученик группы с покрытием открывает урок БЕЗ личной оплаты. */
    public function test_flag_on_group_student_unlocks_lessons_without_personal_payment(): void
    {
        config(['features.b2b_group_payment' => true]);
        $this->payB2bGroup();

        $gate = app(LessonGate::class);
        $this->assertTrue($gate->canWatch($this->student, $this->lesson()));
        // Чужой (не в группе) — нет.
        $this->assertFalse($gate->canWatch($this->outsider, $this->lesson()));
        // Виртуальный ключ 'full' виден только ученику группы.
        $this->assertContains(
            'full',
            StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug),
        );
        $this->assertNotContains(
            'full',
            StudentController::getUserUnlockedTariffs($this->outsider->id, $this->course->slug),
        );
    }

    /** Диапазонный b2b-платёж открывает только покрытые блоки (зеркало block_N). */
    public function test_range_b2b_payment_grants_only_covered_blocks(): void
    {
        config(['features.b2b_group_payment' => true]);

        Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'block_number' => 2,
            'is_free' => false,
        ]);

        $this->payB2bGroup(attrs: ['amount' => 15000, 'start_block' => 1, 'end_block' => 1]);

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertContains('block_1', $keys);
        $this->assertNotContains('full', $keys);

        $gate = app(LessonGate::class);
        $lesson1 = Lesson::query()->where('block_number', 1)->firstOrFail();
        $lesson2 = Lesson::query()->where('block_number', 2)->firstOrFail();
        $this->assertTrue($gate->canWatch($this->student, $lesson1));
        $this->assertFalse($gate->canWatch($this->student, $lesson2));
    }

    /** b2b_group не грант плательщику: ни групп, ни course_user, ни писем/праны/Telegram. */
    public function test_b2b_payment_grants_payer_no_access_and_no_side_effects(): void
    {
        config(['features.b2b_group_payment' => true]);
        $this->mock(CuratorNotifier::class, fn ($mock) => $mock->shouldReceive('paymentPaid')->once());
        Bus::fake();
        Mail::fake();

        $payment = $this->payB2bGroup(quiet: false);

        $this->assertFalse($this->payer->groups()->exists(), 'плательщик не должен попасть в группы курса');
        $this->assertFalse($this->payer->courses()->exists(), 'плательщик не должен записываться на курс');
        Mail::assertNothingSent();
        // Студенческих уведомлений нет (плательщик не студент); SendPaymentToSheetJob
        // — легитимный денежный sync, его не запрещаем.
        Bus::assertNotDispatched(SendTelegramMessageJob::class);
        Bus::assertNotDispatched(SendGroupChatInviteJob::class);
        $this->assertSame(0, (int) $this->payer->fresh()->prana_balance);
        $this->assertTrue($payment->isB2bGroup());
    }

    /** Откат b2b-платежа: ученики остаются в группе, ключи закрываются. */
    public function test_reversal_keeps_students_in_group_but_closes_virtual_keys(): void
    {
        config(['features.b2b_group_payment' => true]);
        $this->mock(CuratorNotifier::class, fn ($mock) => $mock->shouldReceive('paymentPaid')->once());
        Mail::fake();
        Bus::fake();

        $payment = $this->payB2bGroup(quiet: false);
        $gate = app(LessonGate::class);
        $this->assertTrue($gate->canWatch($this->student, $this->lesson()));

        $payment->update(['status' => 'canceled']);

        $this->assertFalse($gate->canWatch($this->student, $this->lesson()), 'после отката b2b-платежа виртуальные ключи закрыты');
        $this->assertTrue(
            (bool) $this->student->groups()->where('groups.id', $this->group->id)->exists(),
            'откат b2b-платежа НЕ должен детачить учеников группы',
        );
    }

    /** Выручка: 30% ЗП от b2b-платежа начисляется как от обычной оплаты (вне флага). */
    public function test_teacher_accrual_counts_b2b_payment_like_normal_revenue(): void
    {
        $this->payB2bGroup(); // 30 000, весь курс, нал в кассу школы

        $this->assertEqualsWithDelta(
            9000.0,
            app(TeacherSalaryService::class)->totalForTeacher($this->teacher->fresh()),
            0.01,
        );
    }

    /**
     * Нал ЛИЧНО преподавателю (H8245 follow-up, MG 14-11-2026): b2b-платёж с
     * received_account=teacher_personal — личный заработок преподавателя, он уже
     * держит 100% наличными; школьная ЗП не начисляется НИСКОЛЬКО (предикат
     * isCourseRevenuePayment, parity OFF; block-калькулятор фильтрует schoolReceived).
     */
    public function test_b2b_paid_teacher_personal_accrues_no_school_salary(): void
    {
        $this->payB2bGroup(attrs: [
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $this->teacher->id,
        ]); // 30 000 налом лично преподавателю

        $this->assertEqualsWithDelta(
            0.0,
            app(TeacherSalaryService::class)->totalForTeacher($this->teacher->fresh()),
            0.01,
        );
        config(['features.b2b_group_payment' => true]);
        $this->assertEqualsWithDelta(
            0.0,
            app(TeacherSalaryService::class)->blockGroupRevenue($this->course->id, 1, $this->group->id),
            0.01,
        );
    }

    /** Блок-калькулятор: b2b-платёж атрибутируется группе только под флагом. */
    public function test_block_group_revenue_attributes_b2b_payment_by_group_id_under_flag(): void
    {
        $service = app(TeacherSalaryService::class);

        $this->payB2bGroup(); // 30 000 на 2 блока → 15 000/блок

        // Флаг OFF: плательщик не член group_user → в блоке 0.
        $this->assertEqualsWithDelta(0.0, $service->blockGroupRevenue($this->course->id, 1, $this->group->id), 0.01);

        config(['features.b2b_group_payment' => true]);
        $this->assertEqualsWithDelta(15000.0, $service->blockGroupRevenue($this->course->id, 1, $this->group->id), 0.01);
        $this->assertEqualsWithDelta(15000.0, $service->blockGroupRevenue($this->course->id, 2, $this->group->id), 0.01);
    }

    /** Должники: покрытые b2b-ученики не попадают в отчёт под флагом, попадают при OFF. */
    public function test_debtors_report_excludes_b2b_covered_students_only_under_flag(): void
    {
        $this->payB2bGroup();

        // Флаг OFF: ученик группы без личной оплаты — должник (прежнее поведение).
        $rowsOff = app(DebtorsReport::class)->query()
            ->where('course_id', $this->course->id)
            ->get();
        $this->assertNotEmpty($rowsOff);

        config(['features.b2b_group_payment' => true]);
        $rowsOn = app(DebtorsReport::class)->query()
            ->where('course_id', $this->course->id)
            ->get();
        $this->assertEmpty(
            $rowsOn,
            'b2b-покрытие группы должно снимать пару (ученик, курс) из должников',
        );
    }
}
