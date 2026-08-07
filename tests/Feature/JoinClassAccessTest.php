<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия (money-core, H071 #6): публичный /class/{id}/join отдавал Zoom-ссылку
 * анониму по неподписанному запросу — id занятий последовательны, так что любой
 * перебором посещал платные живые занятия без оплаты. Теперь редирект на Zoom
 * получают только: валидная подпись (бот/напоминание), студент-участник группы
 * занятия, общее занятие без группы, либо сотрудник.
 */
class JoinClassAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ZOOM = 'https://zoom.us/j/999';

    private function schedule(?Group $group = null): Schedule
    {
        return Schedule::create([
            'title' => 'Живое занятие',
            'group_id' => $group?->id,
            'start' => now()->addMinutes(30),
            'link' => self::ZOOM,
        ]);
    }

    /** @test */
    public function anonymous_unsigned_request_is_denied_and_not_redirected_to_zoom(): void
    {
        $schedule = $this->schedule(Group::create(['name' => 'Поток']));

        $response = $this->get(route('class.join', $schedule));

        $response->assertRedirect(route('login'));
        // Hard guard: never leak the real Zoom URL on the deny path (H071 #6 / H2383).
        $this->assertStringNotContainsString('zoom.us', (string) $response->headers->get('Location'));
        $this->assertSame(0, ScheduleJoinClick::count());
    }

    /** @test */
    public function signed_bot_link_redirects_to_zoom_and_records_click(): void
    {
        $group = Group::create(['name' => 'Поток']);
        $student = User::factory()->create();
        $student->groups()->attach($group->id);
        $schedule = $this->schedule($group);

        $url = $schedule->trackedJoinUrlFor($student, 'telegram');

        $this->get($url)->assertRedirect(self::ZOOM);
        $this->assertDatabaseHas('schedule_join_clicks', [
            'schedule_id' => $schedule->id,
            'user_id' => $student->id,
            'source' => 'telegram',
        ]);
    }

    /** @test */
    public function group_student_can_join_and_click_is_recorded(): void
    {
        $group = Group::create(['name' => 'Поток']);
        $student = User::factory()->create();
        $student->groups()->attach($group->id);
        $schedule = $this->schedule($group);

        $this->actingAs($student)
            ->get(route('class.join', $schedule))
            ->assertRedirect(self::ZOOM);

        $this->assertDatabaseHas('schedule_join_clicks', [
            'schedule_id' => $schedule->id,
            'user_id' => $student->id,
            'source' => 'cabinet',
        ]);
    }

    /** @test */
    public function student_not_in_group_is_forbidden(): void
    {
        $schedule = $this->schedule(Group::create(['name' => 'Поток А']));
        $outsider = User::factory()->create(); // не в группе занятия

        $response = $this->actingAs($outsider)
            ->get(route('class.join', $schedule));

        $response->assertForbidden();
        // 403 must not 302-away to Zoom either (no Location with the paid link).
        $this->assertStringNotContainsString(
            'zoom.us',
            (string) $response->headers->get('Location', '')
        );
        $this->assertSame(0, ScheduleJoinClick::count());
    }

    /** @test */
    public function ungrouped_schedule_is_open_to_any_authenticated_student(): void
    {
        $schedule = $this->schedule(null); // общее занятие без группы
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('class.join', $schedule))
            ->assertRedirect(self::ZOOM);
    }

    /** @test */
    public function staff_can_join_any_class_without_group_membership(): void
    {
        $schedule = $this->schedule(Group::create(['name' => 'Поток А']));
        $teacher = User::factory()->create(['role' => Roles::TEACHER]);

        $this->actingAs($teacher)
            ->get(route('class.join', $schedule))
            ->assertRedirect(self::ZOOM);
    }
}
