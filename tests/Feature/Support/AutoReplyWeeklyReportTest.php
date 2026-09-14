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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3392: недельный отчёт пробы автоответов — секции отчёта, окно 7 дней,
 * медиана латентности, подсказки без ответа, stale-маркеры, --dry без
 * отправки, флаг default OFF, пустая неделя = явная «нет активности».
 */
class AutoReplyWeeklyReportTest extends TestCase
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
        return TelegramSupportAccount::create(['name' => 'rusamskrtam', 'auto_reply_enabled' => true]);
    }

    private function chat(TelegramSupportAccount $account, int $chatId, ?User $user = null): TelegramSupportChat
    {
        return TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['linked_user_id' => $user?->id, 'last_message_at' => now()],
        );
    }

    private function message(
        TelegramSupportAccount $account,
        TelegramSupportChat $chat,
        int $msgId,
        string $direction,
        string $sentAt,
        array $extra = [],
    ): TelegramSupportMessage {
        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $msgId,
            'direction' => $direction,
            'sent_at' => $sentAt,
            ...$extra,
        ]);
    }

    private function event(?int $messageId, string $type, array $meta, string $createdAt): SupportAiReplyEvent
    {
        $event = SupportAiReplyEvent::create([
            'telegram_support_message_id' => $messageId,
            'event_type' => $type,
            'meta' => $meta,
        ]);
        $event->created_at = $createdAt;
        $event->save();

        return $event;
    }

    public function test_report_contains_all_sections_with_correct_numbers(): void
    {
        $account = $this->account();
        $user = User::factory()->create();

        // Chat A: template-автоответ, человек ответил через 40 минут.
        $chatA = $this->chat($account, 9101, $user);
        $botTemplate = $this->message($account, $chatA, 101, 'outgoing', now()->subDays(3)->addMinute()->toDateTimeString(), [
            'role' => 'bot',
            'responder_type' => 'ai',
            'ai_state' => 'sent',
        ]);
        $this->message($account, $chatA, 102, 'outgoing', now()->subDays(3)->addMinutes(41)->toDateTimeString(), [
            'role' => 'human',
            'responder_type' => 'human',
        ]);
        $this->event($botTemplate->id, SupportDmAutoReply::EVENT_SENT, ['via' => 'x', 'kind' => 'template', 'category' => 'D'], now()->subDays(3)->toDateTimeString());

        // Ack с человеческим ответом через 20 минут (медиана ack = 20).
        $botAck = $this->message($account, $chatA, 103, 'outgoing', now()->subDays(2)->addMinute()->toDateTimeString(), [
            'role' => 'bot',
            'responder_type' => 'ai',
            'ai_state' => 'sent',
        ]);
        $this->message($account, $chatA, 104, 'outgoing', now()->subDays(2)->addMinutes(21)->toDateTimeString(), [
            'role' => 'human',
            'responder_type' => 'human',
        ]);
        $this->event($botAck->id, SupportDmAutoReply::EVENT_SENT, ['via' => 'x', 'kind' => 'ack'], now()->subDays(2)->toDateTimeString());

        // Подсказка куратору в чате A — человек ответил через 30 минут.
        $incomingHinted = $this->message($account, $chatA, 105, 'incoming', now()->subDays(1)->toDateTimeString());
        $this->message($account, $chatA, 110, 'outgoing', now()->subDays(1)->addMinutes(30)->toDateTimeString(), [
            'role' => 'human',
            'responder_type' => 'human',
        ]);
        $this->event($incomingHinted->id, SupportDmAutoReply::EVENT_HINTED, ['category' => 'E'], now()->subDays(1)->toDateTimeString());

        // Chat B: подсказка БЕЗ последующего ответа + stale-пропуски.
        $chatB = $this->chat($account, 9202);
        $incomingOrphan = $this->message($account, $chatB, 106, 'incoming', now()->subDays(1)->toDateTimeString());
        $this->event($incomingOrphan->id, SupportDmAutoReply::EVENT_HINTED, ['category' => 'D'], now()->subDays(1)->toDateTimeString());

        $stale1 = $this->message($account, $chatB, 107, 'incoming', now()->subDays(4)->toDateTimeString());
        $stale2 = $this->message($account, $chatB, 108, 'incoming', now()->subDays(5)->toDateTimeString());
        $this->event($stale1->id, SupportDmAutoReply::EVENT_STALE_SKIP, ['via' => 'x'], now()->subDays(4)->toDateTimeString());
        $this->event($stale2->id, SupportDmAutoReply::EVENT_STALE_SKIP, ['via' => 'x'], now()->subDays(5)->toDateTimeString());

        // Вне 7-дневного окна — не попадает.
        $old = $this->message($account, $chatA, 109, 'incoming', now()->subDays(9)->toDateTimeString());
        $this->event($old->id, SupportDmAutoReply::EVENT_STALE_SKIP, ['via' => 'x'], now()->subDays(9)->toDateTimeString());

        $snap = app(AutoReplyWeeklyReport::class)->build();

        $this->assertSame(6, $snap['total']);
        $this->assertSame(['ack' => 1, 'template' => 1], $snap['sent_by_kind']);
        $this->assertSame(['D' => 2, 'E' => 1], $snap['categories']);
        $this->assertSame(2, $snap['hinted']);
        $this->assertSame(1, $snap['hinted_without_answer']);
        $this->assertSame(2, $snap['stale_skips']);
        $this->assertSame(['ack' => 20, 'template' => 40], $snap['latency_median_minutes']);

        foreach ([
            'Автоответы ·',
            'Событий всего: 6',
            'Автоответов: 2 (ack 1 · template 1)',
            'Категории: D 2, E 1',
            'Подсказок куратору: 2 (без ответа: 1)',
            'Пропущено как устаревшие: 2 (backlog era)',
            'Медиана ответа человека: ack ~20 мин · template ~40 мин',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $snap['text']);
        }
    }

    public function test_dry_prints_report_and_sends_nothing_even_when_flag_off(): void
    {
        $this->artisan('support:auto-reply-weekly', ['--dry' => true])
            ->expectsOutputToContain('Активности за неделю нет')
            ->expectsOutputToContain('--dry: Telegram не отправлен.')
            ->assertExitCode(0);

        Http::assertSentCount(0);
    }

    public function test_flag_off_scheduled_run_is_a_noop(): void
    {
        $this->artisan('support:auto-reply-weekly')
            ->expectsOutputToContain('выключен')
            ->assertExitCode(0);

        Http::assertSentCount(0);
    }

    public function test_flag_on_sends_compact_report_to_admins(): void
    {
        config(['features.support_auto_reply_weekly_report' => true]);

        $this->artisan('support:auto-reply-weekly')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'] ?? '', 'Активности за неделю нет'));
    }

    public function test_empty_week_reports_activity_line_explicitly_not_silence(): void
    {
        config(['features.support_auto_reply_weekly_report' => true]);

        $snap = app(AutoReplyWeeklyReport::class)->build();

        $this->assertSame(0, $snap['total']);
        $this->assertStringContainsString('Активности за неделю нет', $snap['text']);
    }
}
