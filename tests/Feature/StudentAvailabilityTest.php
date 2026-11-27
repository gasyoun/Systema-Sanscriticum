<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StudentAvailabilityWindow as Window;
use App\Models\User;
use App\Support\StudentAvailability;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** Окна доступности и OfferGate для новых офферов (рулинги 26-11-2026). */
class StudentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.availability_windows' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function window(User $user, string $day, string $start, string $end, string $source = 'student', string $kind = 'allow', array $extra = []): void
    {
        Window::query()->create(array_merge([
            'user_id' => $user->id, 'source' => $source, 'kind' => $kind,
            'rrule' => 'FREQ=WEEKLY;BYDAY='.$day,
            'start_time' => $start, 'end_time' => $end, 'tzid' => 'Europe/Moscow',
        ], $extra));
    }

    public function test_no_rows_keeps_current_behavior(): void
    {
        $user = User::factory()->create();
        $gate = app(StudentAvailability::class);

        $this->assertTrue($gate->allows($user, '2026-11-30 12:00')); // понедельник
        $this->assertTrue($gate->allowsSlot($user, 'пн 18:00'));
    }

    public function test_her_windows_allow_only_listed_slots(): void
    {
        $user = User::factory()->create();
        $this->window($user, 'TH', '20:00', '23:59');
        $this->window($user, 'FR', '18:00', '23:59');
        $this->window($user, 'SA', '09:00', '11:00');
        $this->window($user, 'SA', '14:30', '23:59');
        $this->window($user, 'SU', '17:00', '23:59');
        $gate = app(StudentAvailability::class);

        // 30.11.2026 — понедельник, 03.12 — четверг, 05.12 — суббота, 06.12 — воскресенье.
        $this->assertFalse($gate->allows($user, '2026-11-30 18:00'));
        $this->assertTrue($gate->allows($user, '2026-12-03 20:00'));
        $this->assertFalse($gate->allows($user, '2026-12-03 19:59'));
        $this->assertTrue($gate->allows($user, '2026-12-05 09:30'));
        $this->assertFalse($gate->allows($user, '2026-12-05 11:01'));
        $this->assertTrue($gate->allows($user, '2026-12-05 14:30'));
        $this->assertTrue($gate->allows($user, '2026-12-06 23:59'));
    }

    public function test_slot_strings_from_waitlist_are_gated(): void
    {
        $user = User::factory()->create();
        $this->window($user, 'SA', '14:30', '23:59');
        $gate = app(StudentAvailability::class);

        $this->assertFalse($gate->allowsSlot($user, 'пн 18:00'));
        $this->assertTrue($gate->allowsSlot($user, 'сб 17:00'));
        $this->assertTrue($gate->allowsSlot($user, 'без времени')); // нечего проверять
    }

    public function test_curator_rows_override_student_rows(): void
    {
        $user = User::factory()->create();
        $this->window($user, 'SA', '09:00', '11:00');
        $gate = app(StudentAvailability::class);

        $this->assertTrue($gate->allows($user, '2026-12-05 10:00'));
        $this->window($user, 'SA', '09:00', '11:00', 'curator', 'deny');
        $this->assertFalse($gate->allows($user, '2026-12-05 10:00'));

        $this->window($user, 'FR', '12:00', '13:00', 'curator', 'allow');
        $this->assertTrue($gate->allows($user, '2026-12-04 12:30'));
    }

    public function test_student_deny_overrides_student_allow(): void
    {
        $user = User::factory()->create();
        $this->window($user, 'SA', '09:00', '11:00');
        $this->window($user, 'SA', '10:00', '10:30', 'student', 'deny');
        $gate = app(StudentAvailability::class);

        // Суббота, 05.12.2026.
        $this->assertTrue($gate->allows($user, '2026-12-05 09:30'));
        $this->assertFalse($gate->allows($user, '2026-12-05 10:00'));
        $this->assertFalse($gate->allows($user, '2026-12-05 10:30'));
        $this->assertTrue($gate->allows($user, '2026-12-05 10:45'));
    }

    public function test_student_deny_is_honored_in_slot_strings(): void
    {
        $user = User::factory()->create();
        $this->window($user, 'SA', '14:30', '23:59');
        $this->window($user, 'SA', '17:00', '18:00', 'student', 'deny');
        $gate = app(StudentAvailability::class);

        $this->assertTrue($gate->allowsSlot($user, 'сб 15:00'));
        $this->assertFalse($gate->allowsSlot($user, 'сб 17:00'));
        $this->assertTrue($gate->allowsSlot($user, 'сб 18:30'));
    }

    public function test_cycle_two_on_two_off_with_anchor(): void
    {
        $user = User::factory()->create();
        // Якорь 01.12.2026 (вторник): 1–2 декабря можно, 3–4 нельзя, 5–6 можно.
        $this->window($user, 'MO', '20:00', '23:59', 'student', 'allow', [
            'repeat_mode' => 'cycle', 'cycle_on_days' => 2, 'cycle_off_days' => 2,
            'anchor_date' => '2026-12-01',
        ]);
        $gate = app(StudentAvailability::class);

        $this->assertFalse($gate->allows($user, '2026-11-30 20:30')); // раньше якоря
        $this->assertTrue($gate->allows($user, '2026-12-01 20:30'));
        $this->assertTrue($gate->allows($user, '2026-12-02 23:59'));
        $this->assertFalse($gate->allows($user, '2026-12-03 20:30'));
        $this->assertFalse($gate->allows($user, '2026-12-04 20:30'));
        $this->assertTrue($gate->allows($user, '2026-12-05 20:30'));
        $this->assertFalse($gate->allows($user, '2026-12-01 19:59')); // вне окна времени
    }

    public function test_cycle_is_honored_for_weekly_slot_strings(): void
    {
        Carbon::setTestNow('2026-11-27 10:00'); // пятница; ближайшая суббота — 28.11
        $user = User::factory()->create();
        $gate = app(StudentAvailability::class);

        // Якорь 26.11: 26–27 можно, 28–29 нельзя → субботний слот не предлагаем.
        Window::query()->create([
            'user_id' => $user->id, 'source' => 'student', 'kind' => 'allow',
            'rrule' => 'FREQ=WEEKLY;BYDAY=SA', 'repeat_mode' => 'cycle',
            'cycle_on_days' => 2, 'cycle_off_days' => 2, 'anchor_date' => '2026-11-26',
            'start_time' => '14:30', 'end_time' => '23:59', 'tzid' => 'Europe/Moscow',
        ]);
        $this->assertFalse($gate->allowsSlot($user, 'сб 15:00'));

        Window::query()->where('user_id', $user->id)->delete();
        // Якорь 27.11: 27–28 можно → субботний слот предлагаем.
        Window::query()->create([
            'user_id' => $user->id, 'source' => 'student', 'kind' => 'allow',
            'rrule' => 'FREQ=WEEKLY;BYDAY=SA', 'repeat_mode' => 'cycle',
            'cycle_on_days' => 2, 'cycle_off_days' => 2, 'anchor_date' => '2026-11-27',
            'start_time' => '14:30', 'end_time' => '23:59', 'tzid' => 'Europe/Moscow',
        ]);
        $this->assertTrue($gate->allowsSlot($user, 'сб 15:00'));
    }

    public function test_student_can_save_cycle_from_cabinet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('student.availability.store'), [
            'tzid' => 'Europe/Moscow',
            'days' => [
                'TH' => [[
                    'enabled' => 1, 'kind' => 'allow', 'start' => '20:00', 'end' => '23:59',
                    'repeat_mode' => 'cycle', 'cycle_on_days' => 2, 'cycle_off_days' => 2,
                    'anchor_date' => '2026-12-01',
                ]],
            ],
        ])->assertRedirect(route('student.availability'));

        $this->assertDatabaseHas('student_availability_windows', [
            'user_id' => $user->id, 'repeat_mode' => 'cycle',
            'cycle_on_days' => 2, 'cycle_off_days' => 2, 'anchor_date' => '2026-12-01 00:00:00',
        ]);
    }

    public function test_flag_off_disables_the_gate(): void
    {
        config(['features.availability_windows' => false]);
        $user = User::factory()->create();
        $this->window($user, 'SA', '09:00', '11:00');
        $gate = app(StudentAvailability::class);

        $this->assertTrue($gate->allows($user, '2026-11-30 18:00'));
        $this->assertTrue($gate->allowsSlot($user, 'пн 18:00'));
    }

    public function test_student_can_save_inconvenient_windows_from_cabinet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('student.availability.store'), [
            'tzid' => 'Europe/Moscow',
            'days' => [
                'SA' => [['enabled' => 1, 'kind' => 'deny', 'start' => '15:00', 'end' => '16:00']],
                'TH' => [['enabled' => 1, 'kind' => 'allow', 'start' => '20:00', 'end' => '23:59']],
            ],
        ])->assertRedirect(route('student.availability'));

        $this->assertDatabaseHas('student_availability_windows', [
            'user_id' => $user->id, 'source' => 'student', 'kind' => 'deny',
            'rrule' => 'FREQ=WEEKLY;BYDAY=SA', 'start_time' => '15:00', 'end_time' => '16:00',
        ]);
        $this->assertDatabaseHas('student_availability_windows', [
            'user_id' => $user->id, 'source' => 'student', 'kind' => 'allow',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TH', 'start_time' => '20:00', 'end_time' => '23:59',
        ]);
    }

    public function test_unsupported_rrule_fails_loudly(): void
    {
        $window = new Window(['rrule' => 'FREQ=DAILY', 'start_time' => '09:00', 'end_time' => '11:00']);

        $this->expectException(InvalidArgumentException::class);
        $window->weekdays();
    }
}
