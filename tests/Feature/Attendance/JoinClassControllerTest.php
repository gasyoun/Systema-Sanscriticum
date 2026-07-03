<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Трекинг-редирект «Подключиться»: фиксирует клик (привязка студент → занятие)
 * и редиректит на настоящий Zoom-URL.
 */
class JoinClassControllerTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): Schedule
    {
        return Schedule::create([
            'title' => 'Занятие',
            'start' => now()->addHour(),
            'link' => 'https://zoom.us/j/123456789',
        ]);
    }

    /** @test */
    public function cabinet_click_records_click_for_authed_user(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule();

        $this->actingAs($user)
            ->get(route('class.join', $schedule))
            ->assertRedirect('https://zoom.us/j/123456789');

        $click = ScheduleJoinClick::firstWhere(['schedule_id' => $schedule->id, 'user_id' => $user->id]);
        $this->assertNotNull($click);
        $this->assertSame('cabinet', $click->source);
        $this->assertSame(1, $click->click_count);
    }

    /** @test */
    public function signed_bot_link_records_click_for_param_user(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule();

        $url = URL::signedRoute('class.join', ['schedule' => $schedule->id, 'u' => $user->id, 'source' => 'vk']);

        $this->get($url)->assertRedirect('https://zoom.us/j/123456789');

        $click = ScheduleJoinClick::firstWhere(['schedule_id' => $schedule->id, 'user_id' => $user->id]);
        $this->assertNotNull($click);
        $this->assertSame('vk', $click->source);
    }

    /** @test */
    public function second_click_increments_count_without_resetting_source(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule();

        $this->actingAs($user)->get(route('class.join', $schedule));
        $this->actingAs($user)->get(route('class.join', $schedule));

        $click = ScheduleJoinClick::firstWhere(['schedule_id' => $schedule->id, 'user_id' => $user->id]);
        $this->assertSame(2, $click->click_count);
        $this->assertSame('cabinet', $click->source);
    }

    /** @test */
    public function anonymous_unsigned_is_sent_to_login_without_recording(): void
    {
        $schedule = $this->schedule();

        // Гейт доступа (#252, H071 #6): аноним по неподписанной ссылке НЕ получает
        // настоящий Zoom-URL (иначе перебором /class/{id}/join можно было ходить на
        // платные живые занятия без оплаты) — его отправляют на вход.
        $this->get(route('class.join', $schedule))
            ->assertRedirect(route('login'));

        $this->assertSame(0, ScheduleJoinClick::count());
    }

    /** @test */
    public function tampered_signature_is_sent_to_login_and_does_not_attribute_param_user(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule();

        // Подписанный URL с подменённым u → подпись невалидна → проваливаемся в
        // ветку анонима: ни клика, ни выдачи Zoom-ссылки — на вход.
        $url = URL::signedRoute('class.join', ['schedule' => $schedule->id, 'u' => $user->id]).'&u=999999';

        $this->get($url)->assertRedirect(route('login'));
        $this->assertSame(0, ScheduleJoinClick::count());
    }
}
