<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\AutoReplyWeeklyReport;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Support\SupportHintsDigestReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5452: гигиена хинт-канала куратора — шум-фильтр (сервис-чат 777000,
 * инфра-префиксы), дедуп per-chat (серия = один хинт до человеческого
 * ответа), 🔥 на деньгах/пробных, дайджест 09:00 с explicit-empty,
 * OFF-инвариант недельного отчёта.
 */
class SupportHintHygieneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private function account(): TelegramSupportAccount
    {
        return TelegramSupportAccount::firstOrCreate(['name' => 'support']);
    }

    private function incoming(?User $user, string $text, int $chatId = 9101): TelegramSupportMessage
    {
        $account = $this->account();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            [
                'linked_user_id' => $user?->id,
                'last_message_at' => now(),
            ],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }

    private function humanOutgoing(int $chatId): TelegramSupportMessage
    {
        $account = $this->account();
        $chat = TelegramSupportChat::query()->where('telegram_chat_id', $chatId)->firstOrFail();

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'outgoing',
            'text' => 'Ответ куратора',
            'sent_at' => now(),
            'responder_type' => 'human',
        ]);
    }

    private function handle(TelegramSupportMessage $incoming, ?User $user = null): array
    {
        return app(SupportDmAutoReply::class)->handle($incoming, $user?->id, 'private');
    }

    public function test_service_chat_777000_is_never_hinted_and_never_answered(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        // Сервисный чат без linked-юзера — система пишет сама себе.
        $incoming = $this->incoming(null, 'Group Transferred to You', 777000);

        $result = $this->handle($incoming);

        $this->assertSame('hint_suppressed', $result['status']);
        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINT_SUPPRESSED)->count());

        $suppressed = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINT_SUPPRESSED)->first();
        $this->assertSame('service_chat', $suppressed->meta['reason']);
        $this->assertSame(777000, $suppressed->meta['telegram_chat_id']);

        // Инвариант: сервис-чатам не уходит ничего — ни хинта, ни ack/автоответа.
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        Http::assertNothingSent();
    }

    public function test_infra_prefix_is_never_hinted(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();
        $incoming = $this->incoming($user, '⚠️ Кабинет: host/ops (guards) — всплеск ошибок');

        $result = $this->handle($incoming, $user);

        $this->assertSame('hint_suppressed', $result['status']);

        $suppressed = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINT_SUPPRESSED)->first();
        $this->assertSame('infra_prefix', $suppressed->meta['reason']);
        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
        Http::assertNothingSent();
    }

    public function test_series_in_one_chat_creates_single_hint_with_counter(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();

        $first = $this->handle($this->incoming($user, 'Оплату куда внести??'), $user);
        $this->assertSame('hinted', $first['status']);

        $second = $this->handle($this->incoming($user, 'оплата за Бюлера блок 10'), $user);
        $this->assertSame('hint_series', $second['status']);

        // Серия = один хинт: второй подсказки куратору нет, счётчик вырос.
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());

        $hint = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->first();
        $this->assertSame(2, $hint->meta['series_count']);
        $this->assertArrayHasKey('series_last_message_id', $hint->meta);
    }

    public function test_human_reply_unblocks_dedup(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();

        $this->handle($this->incoming($user, 'Оплату куда внести??'), $user);
        $this->humanOutgoing(9101);

        $afterReply = $this->handle($this->incoming($user, 'Куратор ответьте пожалуйста'), $user);

        // Человеческий ответ снял блокировку — новый хинт создан.
        $this->assertSame('hinted', $afterReply['status']);
        $this->assertSame(2, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
    }

    public function test_bot_outgoing_does_not_unblock_dedup(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();

        $this->handle($this->incoming($user, 'Оплату куда внести??'), $user);

        // Исходящее бота (responder_type=ai) погасить подсказку не может.
        $account = $this->account();
        $chat = TelegramSupportChat::query()->where('telegram_chat_id', 9101)->firstOrFail();
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'outgoing',
            'text' => 'Намасте! Получили ваше сообщение.',
            'sent_at' => now(),
            'responder_type' => 'ai',
        ]);

        $result = $this->handle($this->incoming($user, 'оплата за 3 часть Гиты'), $user);

        $this->assertSame('hint_series', $result['status']);
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
    }

    public function test_fire_marks_money_hint(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();
        $this->handle($this->incoming($user, 'сколько стоит курс и как оплатить'), $user);

        $hint = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->first();
        $this->assertTrue((bool) $hint->meta['fire']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) ($request->data()['text'] ?? ''), '🔥'));
    }

    public function test_fire_marks_trial_intent(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();
        $this->handle($this->incoming($user, 'хочу попасть завтра к Уше на пробное занятие'), $user);

        $hint = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->first();
        $this->assertTrue((bool) $hint->meta['fire']);
    }

    public function test_plain_question_is_not_marked_fire(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();
        $this->handle($this->incoming($user, 'подскажите, пожалуйста, как устроены домашние задания'), $user);

        $hint = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->first();
        $this->assertFalse((bool) ($hint->meta['fire'] ?? false));
    }

    public function test_fire_from_series_message_sticks_to_open_hint(): void
    {
        config(['features.support_dm_auto_reply' => true]);

        $user = User::factory()->create();

        $this->handle($this->incoming($user, 'подскажите, пожалуйста, как устроены домашние задания'), $user);
        $this->handle($this->incoming($user, 'и ещё: хочу на пробное занятие, как записаться?'), $user);

        $hint = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->first();
        $this->assertTrue((bool) $hint->meta['fire']);
        $this->assertSame(2, $hint->meta['series_count']);
    }

    public function test_dedup_can_be_disabled_via_config(): void
    {
        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram_support.hint_dedup_enabled' => false,
        ]);

        $user = User::factory()->create();

        $this->handle($this->incoming($user, 'Оплату куда внести??'), $user);
        $second = $this->handle($this->incoming($user, 'оплата за Бюлера блок 10'), $user);

        $this->assertSame('hinted', $second['status']);
        $this->assertSame(2, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
    }

    public function test_digest_dry_explicit_empty(): void
    {
        $this->artisan('support:hints-digest', ['--dry' => true])
            ->expectsOutputToContain('0 чатов / 0 сообщений — очередь пуста.')
            ->assertSuccessful();
    }

    public function test_digest_dry_lists_open_chats_with_fire_marker(): void
    {
        $user = User::factory()->create();

        $account = $this->account();
        $fireChat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 688195316],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );
        $answeredChat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 5550001],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        $fireMessage = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $fireChat->id,
            'telegram_chat_id' => 688195316,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'Оплату куда внести??',
            'sent_at' => now()->subHours(162),
        ]);
        $fireEvent = SupportAiReplyEvent::create([
            'telegram_support_message_id' => $fireMessage->id,
            'event_type' => SupportDmAutoReply::EVENT_HINTED,
            'meta' => ['via' => SupportDmAutoReply::VIA, 'fire' => true, 'series_count' => 30],
        ]);
        // Хинт стареет вместе с сообщением: created_at = момент хинта.
        $fireEvent->created_at = now()->subHours(162);
        $fireEvent->save();

        $answeredMessage = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $answeredChat->id,
            'telegram_chat_id' => 5550001,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'как войти в зум',
            'sent_at' => now()->subHours(50),
        ]);
        $answeredEvent = SupportAiReplyEvent::create([
            'telegram_support_message_id' => $answeredMessage->id,
            'event_type' => SupportDmAutoReply::EVENT_HINTED,
            'meta' => ['via' => SupportDmAutoReply::VIA, 'series_count' => 1],
        ]);
        $answeredEvent->created_at = now()->subHours(50);
        $answeredEvent->save();
        // Человек ответил ПОСЛЕ хинта — чат в дайджест не попадает.
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $answeredChat->id,
            'telegram_chat_id' => 5550001,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'outgoing',
            'text' => 'Ссылка: …',
            'sent_at' => now()->subHours(49),
            'responder_type' => 'human',
        ]);

        $snapshot = app(SupportHintsDigestReport::class)->build(30);

        $this->assertSame(1, $snapshot['chats']);
        $this->assertSame(30, $snapshot['messages']);
        $this->assertSame(1, $snapshot['fire']);
        $this->assertSame(162, $snapshot['oldest_hours']);
        $this->assertStringContainsString('🔥 чат 688195316', $snapshot['text']);
        $this->assertStringNotContainsString('5550001', $snapshot['text']);

        $this->artisan('support:hints-digest', ['--dry' => true])
            ->expectsOutputToContain('Ждут ответа: 1 чат / 30 сообщений; старейший — 162 ч; 🔥: 1')
            ->assertSuccessful();
    }

    public function test_weekly_report_has_no_noise_line_without_suppressions(): void
    {
        // OFF-инвариант: нет подавлений — строка шума не появляется.
        $snapshot = app(AutoReplyWeeklyReport::class)->build(7);

        $this->assertSame(0, $snapshot['hint_suppressed']);
        $this->assertStringNotContainsString('Отфильтровано как шум', $snapshot['text']);
    }

    public function test_weekly_report_counts_suppressed_noise_by_reason(): void
    {
        $user = User::factory()->create();
        $incoming = $this->incoming($user, '⚠️ Кабинет: host/ops', 777001);

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => SupportDmAutoReply::EVENT_HINT_SUPPRESSED,
            'meta' => ['via' => SupportDmAutoReply::VIA, 'reason' => 'infra_prefix'],
        ]);
        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => SupportDmAutoReply::EVENT_HINT_SUPPRESSED,
            'meta' => ['via' => SupportDmAutoReply::VIA, 'reason' => 'service_chat'],
        ]);

        $snapshot = app(AutoReplyWeeklyReport::class)->build(7);

        $this->assertSame(2, $snapshot['hint_suppressed']);
        $this->assertSame(['infra_prefix' => 1, 'service_chat' => 1], $snapshot['hint_suppressed_by_reason']);
        $this->assertStringContainsString('Отфильтровано как шум: 2 (infra_prefix 1 · service_chat 1)', $snapshot['text']);
    }
}
