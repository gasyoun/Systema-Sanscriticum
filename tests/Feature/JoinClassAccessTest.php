<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Регрессия (money-core, H071 #6): публичный /class/{id}/join отдавал Zoom-ссылку
 * анониму по неподписанному запросу — id занятий последовательны, так что любой
 * перебором посещал платные живые занятия без оплаты. Теперь редирект на Zoom
 * получают только: валидная подпись (бот/напоминание), студент-участник группы
 * занятия, общее занятие без группы, либо сотрудник.
 *
 * H5081 (remediation H5046 class-join-signed-url-no-expiry): подписанная ссылка
 * стала ВРЕМЕННОЙ (до конца занятия + 30 мин, Schedule::joinLinkExpiry), а
 * контроллер на подписанной ветке перепроверяет canAccess — отозванный из
 * группы студент с сохранённой ссылкой больше не попадает на Zoom.
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

    /** @test */
    public function tracked_join_url_is_temporary_and_expires_after_the_class_window(): void
    {
        $schedule = $this->schedule();
        $student = User::factory()->create();

        $url = $schedule->trackedJoinUrlFor($student, 'telegram');

        // Временная подпись несёт expires, а окно = конец занятия + 30 мин
        // (start + DEFAULT_DURATION_HOURS + grace), а не вечная подпись.
        $this->assertStringContainsString('expires=', (string) $url);
        $expiry = $schedule->joinLinkExpiry();
        $this->assertSame(
            $schedule->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS)->addMinutes(30)->timestamp,
            $expiry->timestamp
        );
    }

    /** @test */
    public function revoked_students_stored_signed_link_no_longer_redirects_to_zoom(): void
    {
        $group = Group::create(['name' => 'Поток']);
        $student = User::factory()->create();
        $student->groups()->attach($group->id);
        $schedule = $this->schedule($group);

        // Ссылка выдана, пока студент был в группе; хранится у него (бот/почта).
        $url = $schedule->trackedJoinUrlFor($student, 'telegram');

        // Отзыв: студент убран из группы — подпись ещё валидна, но доступа нет.
        $student->groups()->detach($group->id);

        $response = $this->get($url);

        $response->assertForbidden();
        $this->assertStringNotContainsString('zoom.us', (string) $response->headers->get('Location', ''));
        $this->assertSame(0, ScheduleJoinClick::count());
    }

    /** @test */
    public function signed_link_expired_after_class_window_is_denied_and_not_redirected_to_zoom(): void
    {
        $group = Group::create(['name' => 'Поток']);
        $student = User::factory()->create();
        $student->groups()->attach($group->id);
        // Занятие уже прошло: start −3ч, конец −1ч, +30 мин grace → ссылка истекла.
        $schedule = Schedule::create([
            'title' => 'Прошедшее занятие',
            'group_id' => $group->id,
            'start' => now()->subHours(3),
            'link' => self::ZOOM,
        ]);

        $url = $schedule->trackedJoinUrlFor($student, 'telegram');
        $this->assertTrue($schedule->joinLinkExpiry()->isPast());

        $response = $this->get($url);

        // Истекшая подпись → ветка анонима: на вход, БЕЗ редиректа на Zoom.
        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString('zoom.us', (string) $response->headers->get('Location'));
        $this->assertSame(0, ScheduleJoinClick::count());
    }

    /** @test */
    public function signed_link_with_unknown_user_id_is_denied(): void
    {
        $schedule = $this->schedule(Group::create(['name' => 'Поток']));

        // Валидно подписанная ссылка на несуществующего пользователя: подпись
        // признаётся, но гейт canAccess держателя не проходит — Zoom не отдаём.
        $url = URL::temporarySignedRoute('class.join', $schedule->joinLinkExpiry(), [
            'schedule' => $schedule->id,
            'u' => 99999999,
            'source' => 'telegram',
        ]);

        $this->get($url)->assertForbidden();
        $this->assertSame(0, ScheduleJoinClick::count());
    }
}
