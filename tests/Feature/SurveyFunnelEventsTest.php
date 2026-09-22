<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SurveyEvent;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5098: воронка анкет — события sent/opened/started/page/completed,
 * идемпотентность записи, приватный агрегат для куратора.
 */
class SurveyFunnelEventsTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'survey_funnel';

    private const SESSION_A = 'aaaaaaaa-1111-4222-8333-444444444444';

    private const SESSION_B = 'bbbbbbbb-1111-4222-8333-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        config(['surveys.enabled' => true]);
    }

    /** @test */
    public function opened_is_recorded_once_per_funnel_session(): void
    {
        foreach ([self::SESSION_A, self::SESSION_A] as $session) {
            $this->withCookies([self::COOKIE => $session])
                ->get('/anketa/exit-price')
                ->assertOk();
        }

        $this->assertSame(1, SurveyEvent::where('event', SurveyEvent::OPENED)->count());
        $this->assertSame(self::SESSION_A, SurveyEvent::where('event', SurveyEvent::OPENED)->value('session_key'));

        // Другая сессия — другая строка; приход без куки получает свою (куку ставит сервер).
        $this->withCookies([self::COOKIE => self::SESSION_B])->get('/anketa/exit-price');
        // call() идёт с пустыми куками: defaultCookies тест-клиента к нему не прилипают.
        $this->call('GET', '/anketa/exit-price')->assertCookie(self::COOKIE);
        $this->assertSame(3, SurveyEvent::where('event', SurveyEvent::OPENED)->count());
    }

    /** @test */
    public function started_and_page_events_are_deduplicated(): void
    {
        // postJson без withCredentials() не передаёт defaultCookies — включаем.
        $this->withCredentials()->withCookies([self::COOKIE => self::SESSION_A])
            ->postJson('/anketa/exit-price/event', ['event' => 'started'])
            ->assertNoContent();

        $this->withCredentials()->withCookies([self::COOKIE => self::SESSION_A])
            ->postJson('/anketa/exit-price/event', ['event' => 'started'])
            ->assertNoContent();
        $this->assertSame(1, SurveyEvent::where('event', SurveyEvent::STARTED)->count());

        $this->withCredentials()->withCookies([self::COOKIE => self::SESSION_A])
            ->postJson('/anketa/exit-price/event', ['event' => 'page', 'page' => 2])
            ->assertNoContent();
        $this->withCredentials()->withCookies([self::COOKIE => self::SESSION_A])
            ->postJson('/anketa/exit-price/event', ['event' => 'page', 'page' => 2])
            ->assertNoContent();
        $this->withCredentials()->withCookies([self::COOKIE => self::SESSION_A])
            ->postJson('/anketa/exit-price/event', ['event' => 'page', 'page' => 3])
            ->assertNoContent();

        $pages = SurveyEvent::where('event', SurveyEvent::PAGE)->orderBy('page_index')->pluck('page_index');
        $this->assertSame([2, 3], $pages->all());
    }

    /** @test */
    public function event_endpoint_rejects_bad_input_and_disabled_surveys(): void
    {
        $this->postJson('/anketa/exit-price/event', ['event' => 'hacked'])->assertStatus(422);
        $this->postJson('/anketa/exit-price/event', ['event' => 'page', 'page' => 9999])->assertStatus(422);

        config(['surveys.enabled' => false]);
        $this->postJson('/anketa/exit-price/event', ['event' => 'started'])->assertNotFound();

        $this->assertSame(0, SurveyEvent::count());
    }

    /** @test */
    public function completed_event_is_linked_to_response_session_and_invitation(): void
    {
        $user = User::factory()->create();
        SurveyInvitation::create([
            'survey_slug' => 'exit-price',
            'user_id' => $user->id,
            'telegram_chat_id' => 424242,
            'channel' => 'telegram',
            'status' => SurveyInvitation::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($user)
            ->withCookies([self::COOKIE => self::SESSION_A])
            ->post('/anketa/exit-price', [
                'what_happened' => 'Не было времени',
                'reward_choice' => 'none',
            ])->assertRedirect('/anketa/exit-price?done=1');

        $event = SurveyEvent::where('event', SurveyEvent::COMPLETED)->firstOrFail();
        $this->assertSame('exit-price', $event->survey_slug);
        $this->assertSame(self::SESSION_A, $event->session_key);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame(SurveyResponse::firstOrFail()->id, $event->survey_response_id);
        $this->assertSame(SurveyInvitation::firstOrFail()->id, $event->survey_invitation_id);

        // Повторная отправка — новый ответ, новая строка completed.
        $this->actingAs($user)
            ->withCookies([self::COOKIE => self::SESSION_A])
            ->post('/anketa/exit-price', [
                'what_happened' => 'Не помню деталей',
                'reward_choice' => 'none',
            ])->assertRedirect();

        $this->assertSame(2, SurveyResponse::count());
        $this->assertSame(2, SurveyEvent::where('event', SurveyEvent::COMPLETED)->count());
    }

    /** @test */
    public function guest_submission_still_writes_completed_without_invitation(): void
    {
        $this->withCookies([self::COOKIE => self::SESSION_A])
            ->post('/anketa/exit-price', [
                'what_happened' => 'Не было времени',
                'reward_choice' => 'none',
            ])->assertRedirect();

        $event = SurveyEvent::where('event', SurveyEvent::COMPLETED)->firstOrFail();
        $this->assertSame(self::SESSION_A, $event->session_key);
        $this->assertNull($event->survey_invitation_id);
        $this->assertNull($event->user_id);
    }

    /** @test */
    public function funnel_aggregate_is_gated_and_leaks_no_pii(): void
    {
        SurveyResponse::create([
            'survey_slug' => 'exit-price',
            'answers' => ['what_happened' => 'Не было времени'],
            'contact' => 'topsecret@person.ru',
            'reward_choice' => 'prana',
        ]);
        SurveyEvent::create(['survey_slug' => 'exit-price', 'event' => SurveyEvent::OPENED, 'session_key' => self::SESSION_A]);

        $this->get('/admin/surveys/exit-price')->assertForbidden();

        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)
            ->get('/admin/surveys/exit-price')
            ->assertOk()
            ->assertSee('Воронка и агрегаты', false)
            ->assertDontSee('topsecret@person.ru');

        $this->actingAs($manager)->get('/admin/surveys/no-such-wave')->assertNotFound();
    }

    /** @test */
    public function invite_send_command_records_sent_event(): void
    {
        config([
            'services.telegram.student_bot_token' => 'test-token',
            'services.telegram.student_bot_username' => 'samskrtamru_bot',
            'app.url' => 'https://samskrte.ru',
        ]);
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777, 'username' => 'samskrtamru_bot', 'is_bot' => true]]),
            'api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
        ]);

        $user = User::factory()->create([
            'wants_messenger_announcements' => true,
            'role' => null,
            'is_admin' => false,
        ]);
        $user->forceFill(['telegram_id' => 510000000 + $user->id])->save();
        $group = Group::factory()->create(['status' => 'active']);
        $group->users()->attach($user->id);

        $this->artisan('surveys:send-student-invites', [
            '--send' => true,
            '--slug' => 'student-purchase-2026-09',
        ])->assertExitCode(0);

        $invitation = SurveyInvitation::where('status', SurveyInvitation::STATUS_SENT)->firstOrFail();
        $event = SurveyEvent::where('event', SurveyEvent::SENT)->firstOrFail();
        $this->assertSame('student-purchase-2026-09', $event->survey_slug);
        $this->assertSame($invitation->id, $event->survey_invitation_id);
        $this->assertSame($user->id, $event->user_id);
    }
}
