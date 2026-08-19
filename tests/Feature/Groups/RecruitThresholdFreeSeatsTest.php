<?php

declare(strict_types=1);

namespace Tests\Feature\Groups;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Порог набора `min_size` считает ПЛАТНЫЕ места.
 *
 * Рулинг MG 19-08-2026: льготников в порог считать, полностью бесплатных — нет,
 * «тут и везде». Живой случай: у субботнего потока Уши («Строфы из Гиты | сб
 * 12:00») двое участников с оплатой 0 ₽ — они завышали «набрано». А у «Сказаний
 * о Нале» льготное место, наоборот, законно занято и в порог входит.
 *
 * Категории различимы в данных: «Льготник» — заявленный статус (97 человек на
 * 19-08-2026), а «Записался» с нулевой суммой — необъявленный бесплатник (23).
 */
class RecruitThresholdFreeSeatsTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = Course::factory()->create(['title' => 'Строфы из Гиты']);
        $this->group = Group::create(['name' => 'сб 12:00', 'min_size' => 8]);
        $this->course->groups()->attach($this->group);
    }

    private function member(string $name, float $paid, ?string $status = 'Записался'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->group->users()->attach($user);

        if ($status !== null) {
            $user->courses()->attach($this->course, ['status' => $status]);
        }

        $this->pay($user, $paid);

        return $user;
    }

    private function pay(User $user, float $amount, string $status = 'paid', bool $conditional = false): void
    {
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => $amount,
            'tariff' => 'block_1',
            'status' => $status,
            'is_conditional' => $conditional,
        ]);
    }

    /** @test */
    public function a_fully_free_participant_does_not_count_toward_the_threshold(): void
    {
        $this->member('Платящая', 6000);
        $this->member('Антонова Елена', 0);
        $this->member('Артеменко Оксана', 0);

        $this->assertSame(3, $this->group->activeUsers()->count(), 'состав группы не меняется');
        $this->assertSame(1, $this->group->membersTowardMinSize(), 'в порог идёт только платящая');
    }

    /** @test */
    public function a_privileged_seat_counts_even_with_a_zero_payment(): void
    {
        $this->member('Платящий', 6000);
        $this->member('Литвиненко Ольга', 0, status: 'Льготник');

        $this->assertSame(2, $this->group->membersTowardMinSize());
    }

    /** @test */
    public function paying_something_at_least_once_is_enough(): void
    {
        $user = $this->member('Доплатил позже', 0);
        $this->pay($user, 6000);

        $this->assertSame(1, $this->group->membersTowardMinSize());
    }

    /** @test */
    public function an_unpaid_payment_row_does_not_buy_a_seat(): void
    {
        $user = User::factory()->create();
        $this->group->users()->attach($user);
        $user->courses()->attach($this->course, ['status' => 'Записался']);
        $this->pay($user, 6000, status: 'pending');

        $this->assertSame(0, $this->group->membersTowardMinSize());
    }

    /** @test */
    public function someone_who_left_never_counts_however_much_they_paid(): void
    {
        $user = $this->member('Ушёл', 12000);
        $this->group->users()->updateExistingPivot($user->id, [
            'left_at' => now(),
            'left_reason' => 'status',
        ]);

        $this->assertSame(0, $this->group->fresh()->membersTowardMinSize());
    }

    /** @test */
    public function is_recruited_uses_the_paid_count_not_the_roster(): void
    {
        $this->group->update(['min_size' => 3]);

        $this->member('Платит 1', 6000);
        $this->member('Платит 2', 6000);
        $this->member('Бесплатный', 0);

        // По составу трое — порог как будто взят; по платным местам двое.
        $this->assertSame(3, $this->group->activeUsers()->count());
        $this->assertFalse($this->group->isRecruited());

        $this->member('Платит 3', 6000);
        $this->assertTrue($this->group->isRecruited());
    }

    /** @test */
    public function a_group_without_courses_falls_back_to_its_roster(): void
    {
        $orphan = Group::create(['name' => 'без курса', 'min_size' => 2]);
        $orphan->users()->attach(User::factory()->create());

        // Сопоставлять оплату не с чем — молча отдавать ноль было бы хуже.
        $this->assertSame(1, $orphan->membersTowardMinSize());
    }

    /** @test */
    public function a_group_with_no_min_size_is_always_recruited(): void
    {
        $free = Group::create(['name' => 'без порога']);
        $free->users()->attach(User::factory()->create());

        $this->assertTrue($free->isRecruited());
    }

    /** @test */
    public function conditional_access_under_a_promise_does_not_hold_a_seat(): void
    {
        $user = User::factory()->create(['name' => 'Под обещание']);
        $this->group->users()->attach($user);
        $user->courses()->attach($this->course, ['status' => 'Записался']);
        // «Доступ под обещание» — тоже строка paid, но денег за ней нет.
        $this->pay($user, 6000, conditional: true);

        $this->assertSame(0, $this->group->membersTowardMinSize());

        $this->pay($user, 6000);
        $this->assertSame(1, $this->group->membersTowardMinSize(), 'заплатил — место засчитано');
    }
}
