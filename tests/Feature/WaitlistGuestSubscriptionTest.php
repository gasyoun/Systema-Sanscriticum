<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMaxMagnetUpdate;
use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Jobs\ProcessVkMagnetCallback;
use App\Jobs\SendLeadMagnet;
use App\Jobs\SendMessengerAlerts;
use App\Jobs\SendWaitlistGuestStatus;
use App\Models\Course;
use App\Models\Group;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\WaitlistNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Подписка гостя на статусы курса через deep-link (H3339): binding-токен
 * гейтится наличием status_block, вебхук привязывает чат без файла-магнита,
 * приветствие = полный словарь статусов, notify() доходит до связанных
 * гостей и честно уменьшает ручной хвост, перепривязка не дублируется.
 */
class WaitlistGuestSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function makeStatusLanding(string $family = 'kashmir-family', array $extra = []): LandingPage
    {
        return LandingPage::create(array_merge([
            'title' => 'Кашмир',
            'slug' => 'status-'.uniqid(),
            'is_active' => true,
            'content' => [['type' => 'status_block', 'data' => ['course_family' => $family]]],
        ], $extra));
    }

    private function makeGroupWithFamily(string $family = 'kashmir-family'): Group
    {
        $course = Course::factory()->create(['course_family' => $family]);
        $group = Group::create([
            'name' => 'КШ-3',
            'slug' => 'ksh3-'.uniqid(),
            'status' => 'forming',
            'min_size' => 8,
            'planned_start_date' => today()->addDays(2),
        ]);
        $course->groups()->attach($group->id);

        return $group;
    }

    // ================= 1. Гейт выдачи токена =================

    /** @test */
    public function store_issues_binding_token_for_status_block_landing(): void
    {
        MarketingSetting::create(['tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-token']);
        RateLimiter::clear('lead-submit:127.0.0.1');
        $landing = $this->makeStatusLanding();

        $this->post('/leads/store', [
            'contact' => '+79990000001',
            'email' => 'guest@example.com',
            'landing_page_id' => $landing->id,
        ])->assertRedirect();

        $lead = Lead::where('email', 'guest@example.com')->firstOrFail();
        $this->assertNotNull($lead->magnet_token);
        $this->assertSame('telegram', $lead->magnet_channel);
    }

    /** @test */
    public function store_does_not_issue_token_without_status_block_or_magnet(): void
    {
        RateLimiter::clear('lead-submit:127.0.0.1');
        $landing = LandingPage::create([
            'title' => 'Обычный',
            'slug' => 'plain-'.uniqid(),
            'is_active' => true,
        ]);

        $this->post('/leads/store', [
            'contact' => '+79990000002',
            'email' => 'plain@example.com',
            'landing_page_id' => $landing->id,
        ])->assertRedirect();

        $lead = Lead::where('email', 'plain@example.com')->firstOrFail();
        $this->assertNull($lead->magnet_token);
    }

    /** @test */
    public function one_click_issues_binding_and_flash_shows_connect_links(): void
    {
        MarketingSetting::create(['tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-token']);
        RateLimiter::clear('lead-submit:127.0.0.1');
        $landing = $this->makeStatusLanding();
        $user = User::factory()->create(['telegram_username' => 'student']);

        $this->actingAs($user)->post('/leads/one-click', ['landing_page_id' => $landing->id])
            ->assertSessionHas('success');

        $lead = Lead::where('user_id', $user->id)->where('landing_page_id', $landing->id)->firstOrFail();
        $this->assertNotNull($lead->magnet_token);
        $this->assertSame('telegram', $lead->magnet_channel);

        // Дубликат тоже видит блок: токен досыпается существующей заявке.
        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->actingAs($user)->post('/leads/one-click', ['landing_page_id' => $landing->id])
            ->assertSessionHas('success')
            ->assertSessionHas('status_connect_links', fn ($links) => isset($links['telegram']));
    }

    // ================= 2-3-5. Вебхук: биндинг без файла + словарь + дедуп =================

    /** @test */
    public function telegram_webhook_binds_chat_sends_vocabulary_welcome_and_no_file(): void
    {
        Queue::fake([SendLeadMagnet::class]);
        MarketingSetting::create(['tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $landing = $this->makeStatusLanding();
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'contact' => '+79990000003', 'email' => 'sub@example.com',
            'magnet_token' => 'SUBTOKEN1234', 'magnet_channel' => 'telegram',
        ]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 777001], 'text' => '/start SUBTOKEN1234'],
        ]))->handle();

        $this->assertSame(777001, $lead->fresh()->telegram_chat_id);
        $this->assertNull($lead->fresh()->magnet_delivered_at);

        // Приветствие = полный словарь статусов + обещание тишины.
        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains((string) $request['chat_id'], '777001')
                && str_contains($text, WaitlistNotifier::vocabulary()['recruiting'])
                && str_contains($text, WaitlistNotifier::vocabulary()['postponed'])
                && str_contains($text, 'Других сообщений по этому курсу не будет');
        });

        // Файл не уходит никогда: подписка без магнита не входит в файловый конвейер.
        Queue::assertNotPushed(SendLeadMagnet::class);
    }

    /** @test */
    public function rebinding_the_same_channel_does_not_repeat_the_welcome(): void
    {
        MarketingSetting::create(['tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $landing = $this->makeStatusLanding();
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'contact' => '+79990000004', 'email' => 're@example.com',
            'magnet_token' => 'RETOKEN12345', 'magnet_channel' => 'telegram',
        ]);

        $update = ['message' => ['chat' => ['id' => 777002], 'text' => '/start RETOKEN12345']];
        (new ProcessTelegramMagnetUpdate($update))->handle();
        (new ProcessTelegramMagnetUpdate($update))->handle();

        $welcomeSends = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains((string) $pair[0]->url(), 'sendMessage'))
            ->count();

        $this->assertSame(1, $welcomeSends, 'Приветствие должно уйти ровно один раз при перепривязке');
        $this->assertSame(777002, $lead->fresh()->telegram_chat_id);
    }

    /** @test */
    public function magnet_landing_bind_still_dispatches_file_and_skips_welcome(): void
    {
        Queue::fake([SendLeadMagnet::class]);
        MarketingSetting::create(['tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $landing = $this->makeStatusLanding(extra: [
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/real.pdf',
            'content' => [
                ['type' => 'status_block', 'data' => ['course_family' => 'kashmir-family']],
            ],
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'contact' => '+79990000005', 'email' => 'mag@example.com',
            'magnet_token' => 'MAGTOKEN1234', 'magnet_channel' => 'telegram',
        ]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 777003], 'text' => '/start MAGTOKEN1234'],
        ]))->handle();

        $this->assertSame(777003, $lead->fresh()->telegram_chat_id);
        // Настоящий магнитный сценарий не тронут: файл в конвейере, словаря нет.
        Queue::assertPushed(SendLeadMagnet::class);
        Http::assertNothingSent();
    }

    // ================= 4. Доставка связанному гостю =================

    /** @test */
    public function notify_delivers_to_bound_guest_and_honestly_shrinks_manual_tail(): void
    {
        Queue::fake();

        $group = $this->makeGroupWithFamily('guest-family');
        $landing = $this->makeStatusLanding('guest-family');

        // Связанный гость: чат привязан, аккаунта нет.
        $boundGuest = Lead::create([
            'landing_page_id' => $landing->id,
            'contact' => '@guest', 'email' => 'bound@example.com',
            'magnet_token' => 'GUESTTOK1234', 'magnet_channel' => 'telegram',
            'telegram_chat_id' => 555000111,
        ]);

        // Несвязанный гость того же лендинга: остаётся в ручном хвосте.
        Lead::create([
            'landing_page_id' => $landing->id,
            'contact' => '@quiet', 'email' => 'quiet@example.com',
            'magnet_token' => 'QUIETTOK123', 'magnet_channel' => 'telegram',
        ]);

        // Сматченный ученик: доставляется старым путём SendMessengerAlerts.
        $studentId = User::factory()->create()->id;
        WaitlistEntry::create(['group_id' => $group->id, 'user_id' => $studentId, 'name' => 'Ученик', 'contact' => '+70000000001']);
        WaitlistEntry::create(['group_id' => $group->id, 'name' => 'Гость листа', 'contact' => '+70000000002']);

        $result = app(WaitlistNotifier::class)->notify($group, WaitlistNotifier::KIND_MANUAL, 'Статус теста');

        Queue::assertPushed(SendMessengerAlerts::class, 1);
        Queue::assertPushed(SendWaitlistGuestStatus::class, fn ($job) => $job->leadId === $boundGuest->id);
        Queue::assertPushed(SendWaitlistGuestStatus::class, 1);

        $this->assertSame(1, $result['guests']);
        $this->assertSame(2, $result['messengers']); // ученик + связанный гость
        $this->assertSame(0, $result['manual']);     // хвост уменьшился честно
    }

    /** @test */
    public function guests_of_other_course_families_are_not_delivered(): void
    {
        Queue::fake();

        $group = $this->makeGroupWithFamily('right-family');
        $otherLanding = $this->makeStatusLanding('wrong-family');
        Lead::create([
            'landing_page_id' => $otherLanding->id,
            'contact' => '@alien', 'email' => 'alien@example.com',
            'magnet_token' => 'ALIENTOK123', 'magnet_channel' => 'telegram',
            'telegram_chat_id' => 555999,
        ]);

        $result = app(WaitlistNotifier::class)->notify($group, WaitlistNotifier::KIND_MANUAL, 'Статус');

        Queue::assertNotPushed(SendWaitlistGuestStatus::class);
        $this->assertSame(0, $result['guests']);
    }

    // ================= Вебхуки VK / Max: биндинг подписки =================

    /** @test */
    public function vk_webhook_binds_chat_and_sends_vocabulary_welcome(): void
    {
        MarketingSetting::create([
            'vk_group_screen_name' => 'school',
            'vk_access_token' => 'vk-token',
            'tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-token',
        ]);
        Http::fake(['api.vk.com/*' => Http::response(['response' => 1])]);

        $landing = $this->makeStatusLanding();
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'contact' => '@vkguest', 'email' => 'vk@example.com',
            'magnet_token' => 'VKTOKEN123456', 'magnet_channel' => 'vk',
        ]);

        (new ProcessVkMagnetCallback([
            'type' => 'message_new',
            'object' => ['message' => ['from_id' => 909001, 'ref' => 'VKTOKEN123456']],
        ]))->handle();

        $this->assertSame(909001, $lead->fresh()->vk_user_id);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'messages.send')
                && str_contains((string) ($request['message'] ?? ''), 'Других сообщений по этому курсу не будет');
        });
    }

    /** @test */
    public function max_webhook_binds_chat_via_start_payload(): void
    {
        MarketingSetting::create([
            'max_bot_username' => 'maxbot', 'max_bot_token' => 'max-token',
            'tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-token',
        ]);
        Http::fake(['botapi.max.ru/*' => Http::response(['ok' => true])]);

        $landing = $this->makeStatusLanding();
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'contact' => '@maxguest', 'email' => 'max@example.com',
            'magnet_token' => 'MAXTOKEN12345', 'magnet_channel' => 'max',
        ]);

        (new ProcessMaxMagnetUpdate([
            'update_type' => 'message_created',
            'message' => [
                'sender' => ['user_id' => '808001'],
                'start_payload' => 'MAXTOKEN12345',
                'body' => ['text' => ''],
            ],
        ]))->handle();

        $this->assertSame('808001', $lead->fresh()->max_user_id);

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), WaitlistNotifier::vocabulary()['recruited']));
    }
}
