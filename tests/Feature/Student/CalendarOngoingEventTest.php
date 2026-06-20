<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Карточка занятия в расписании кабинета должна жить до КОНЦА занятия,
 * а не исчезать в момент его начала.
 */
class CalendarOngoingEventTest extends TestCase
{
    use RefreshDatabase;

    private function studentInGroup(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Поток А']);
        $user->groups()->attach($group->id);

        return [$user, $group];
    }

    /** @test */
    public function ongoing_event_stays_visible_until_it_ends(): void
    {
        [$user, $group] = $this->studentInGroup();

        // (a) Идёт прямо сейчас: старт прошёл, конец впереди.
        Schedule::create([
            'title' => 'Идущее занятие',
            'link' => 'https://zoom.us/j/ongoing',
            'start' => now()->subMinutes(30),
            'end' => now()->addHour(),
            'group_id' => $group->id,
        ]);
        // (b) Будущее.
        Schedule::create([
            'title' => 'Будущее занятие',
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
            'group_id' => $group->id,
        ]);
        // (c) Завершённое — не показываем.
        Schedule::create([
            'title' => 'Прошедшее занятие',
            'start' => now()->subHours(3),
            'end' => now()->subHours(2),
            'group_id' => $group->id,
        ]);

        $response = $this->actingAs($user)->get(route('student.calendar'));

        $response->assertOk()
            ->assertSee('Идущее занятие')
            ->assertSee('https://zoom.us/j/ongoing', escape: false)
            ->assertSee('Будущее занятие')
            ->assertDontSee('Прошедшее занятие');
    }

    /** @test */
    public function event_without_end_uses_default_duration_window(): void
    {
        [$user, $group] = $this->studentInGroup();

        // Без end, стартовало 30 мин назад → ещё в окне DEFAULT_DURATION_HOURS.
        Schedule::create([
            'title' => 'Идёт без конца',
            'start' => now()->subMinutes(30),
            'group_id' => $group->id,
        ]);
        // Без end, стартовало 3 часа назад → окно вышло, скрываем.
        Schedule::create([
            'title' => 'Протухло без конца',
            'start' => now()->subHours(3),
            'group_id' => $group->id,
        ]);

        $response = $this->actingAs($user)->get(route('student.calendar'));

        $response->assertOk()
            ->assertSee('Идёт без конца')
            ->assertDontSee('Протухло без конца');
    }
}
