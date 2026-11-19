<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Http\Controllers\StudentController;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Access\LessonGate;
use App\Services\DebtorsReport;
use App\Services\Payments\B2bGroupEntitlement;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Смешанная группа под B2B-оплатой «за группу целиком» (H8245): часть учеников
 * покрывает платёж юрлица, а ученик с отметкой group_user.b2b_self_pay платит
 * блоки сам (флаг features.b2b_group_self_pay поверх b2b_group_payment).
 *
 * Контракт:
 *  1. Флаг OFF — отметка игнорируется, групповой платёж покрывает всех.
 *  2. Флаг ON — отмеченному групповой платёж не даёт ни ключей, ни снятия из
 *     должников; доступ у него только по собственным платежам.
 *  3. Сосед по группе без отметки покрыт как раньше.
 *  4. Выручка блока по группе = групповой платёж + личный платёж отмеченного.
 */
class B2bGroupSelfPayTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private User $covered;

    private User $selfPayer;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Moscow']);
        config(['features.b2b_group_payment' => true]);

        $teacher = Teacher::create(['name' => 'Преподаватель гр.64']);
        $this->course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $this->course->id, 'number' => 1, 'is_active' => true, 'starts_at' => '2026-11-01']);
        CourseBlock::create(['course_id' => $this->course->id, 'number' => 2, 'is_active' => true, 'starts_at' => '2026-12-01']);

        $this->group = Group::factory()->create();
        $this->course->groups()->attach($this->group->id);

        foreach ([1, 2] as $block) {
            Lesson::factory()->create([
                'course_id' => $this->course->id,
                'group_id' => $this->group->id,
                'block_number' => $block,
                'is_free' => false,
            ]);
        }

        $this->covered = User::factory()->create();
        $this->selfPayer = User::factory()->create();
        $this->payer = User::factory()->create();

        $this->covered->groups()->attach($this->group->id);
        $this->selfPayer->groups()->attach($this->group->id, ['b2b_self_pay' => true]);
    }

    /** Платёж юрлица за группу целиком: 30 000 на весь курс (2 блока). */
    private function payB2bGroup(): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->payer->id,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'tariff' => Payment::TARIFF_B2B_GROUP,
            'amount' => 30000,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'payment_method' => 'cash',
        ]))->fresh();
    }

    /** Личная оплата блока 1 учеником, который платит сам. */
    private function payOwnBlockOne(): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->selfPayer->id,
            'course_id' => $this->course->id,
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'amount' => 5000,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'payment_method' => 'sbp',
        ]))->fresh();
    }

    private function lesson(int $block): Lesson
    {
        return Lesson::query()
            ->where('course_id', $this->course->id)
            ->where('block_number', $block)
            ->firstOrFail();
    }

    /** @return list<string> */
    private function keys(User $user): array
    {
        return StudentController::getUserUnlockedTariffs($user->id, $this->course->slug);
    }

    /** @return list<int> */
    private function debtorIds(): array
    {
        return app(DebtorsReport::class)->query()
            ->where('course_id', $this->course->id)
            ->get()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Дефолт OFF: отметка игнорируется — групповой платёж покрывает и отмеченного. */
    public function test_flag_off_mark_is_ignored_and_group_payment_covers_everyone(): void
    {
        $this->assertFalse(B2bGroupEntitlement::selfPayEnabled());
        $this->payB2bGroup();

        $this->assertContains('full', $this->keys($this->selfPayer));
        $this->assertTrue(app(LessonGate::class)->canWatch($this->selfPayer->fresh(), $this->lesson(1)));
        $this->assertSame([], $this->debtorIds());
    }

    /** Флаг ON: отмеченному групповой платёж ключей не даёт; сосед по группе покрыт. */
    public function test_flag_on_self_payer_gets_no_virtual_keys_but_neighbour_does(): void
    {
        config(['features.b2b_group_self_pay' => true]);
        $this->payB2bGroup();

        $this->assertSame([], $this->keys($this->selfPayer));
        $this->assertFalse(app(LessonGate::class)->canWatch($this->selfPayer->fresh(), $this->lesson(1)));

        $this->assertContains('full', $this->keys($this->covered));
        $this->assertTrue(app(LessonGate::class)->canWatch($this->covered->fresh(), $this->lesson(1)));
    }

    /** Отмеченный с личной оплатой блока 1 открывает только блок 1, хотя группа оплачена целиком. */
    public function test_self_payer_unlocks_only_blocks_he_paid_himself(): void
    {
        config(['features.b2b_group_self_pay' => true]);
        $this->payB2bGroup();
        $this->payOwnBlockOne();

        $keys = $this->keys($this->selfPayer);
        $this->assertContains('block_1', $keys);
        $this->assertNotContains('full', $keys);

        $gate = app(LessonGate::class);
        $this->assertTrue($gate->canWatch($this->selfPayer->fresh(), $this->lesson(1)));
        $this->assertFalse($gate->canWatch($this->selfPayer->fresh(), $this->lesson(2)));
    }

    /** Должники: отмеченный без своих платежей — в отчёте, покрытый сосед — нет. */
    public function test_debtors_report_keeps_self_payer_and_drops_covered_neighbour(): void
    {
        config(['features.b2b_group_self_pay' => true]);
        $this->payB2bGroup();

        $ids = $this->debtorIds();
        $this->assertContains($this->selfPayer->id, $ids);
        $this->assertNotContains($this->covered->id, $ids);
    }

    /** Без группового платежа отметка ничего не меняет: оба ученика — должники. */
    public function test_without_group_payment_both_students_are_debtors(): void
    {
        config(['features.b2b_group_self_pay' => true]);

        $ids = $this->debtorIds();
        $this->assertContains($this->selfPayer->id, $ids);
        $this->assertContains($this->covered->id, $ids);
    }

    /** Выручка блока по группе: групповой платёж + личный платёж отмеченного, без задвоения. */
    public function test_block_revenue_sums_group_payment_and_self_payer_payment(): void
    {
        config(['features.b2b_group_self_pay' => true]);
        $this->payB2bGroup(); // 30 000 на 2 блока → 15 000/блок
        $this->payOwnBlockOne(); // 5 000 на блок 1

        $service = app(TeacherSalaryService::class);
        $this->assertEqualsWithDelta(20000.0, $service->blockGroupRevenue($this->course->id, 1, $this->group->id), 0.01);
        $this->assertEqualsWithDelta(15000.0, $service->blockGroupRevenue($this->course->id, 2, $this->group->id), 0.01);
    }
}
