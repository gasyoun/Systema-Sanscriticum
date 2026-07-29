<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\WebSupportRollupAggregator;
use App\Services\TelegramSupport\SupportDailyRollupAggregator;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1837 (S10) — паритет измерения веб-чата и TG-support.
 *
 * Главный тест здесь — {@see self::test_daily_rollup_counts_include_both_channels_for_the_same_day()}:
 * ровно то условие приёмки из handoff'а, которое до S10 было невыполнимо
 * (`support_daily_rollups` физически не мог описать веб-тред).
 */
class WebSupportRollupAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'features.support_web_rollups' => true,
            'support.rollup.unresolved_after_hours' => 24,
        ]);

        // Время заморожено намеренно. Тесты ниже ставят сообщения относительно
        // «сейчас» (`now()->subMinutes(30)`) и сверяют их с `now()->toDateString()`;
        // на живых часах прогон, попавший на полночь, разводит две половины по
        // разным сутками и тест падает по причине, не связанной с кодом (ровно это
        // случилось на полном прогоне 29→30-07-2026).
        $this->travelTo(CarbonImmutable::parse('2026-07-25 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_daily_rollup_counts_include_both_channels_for_the_same_day(): void
    {
        $day = '2026-07-20';

        $this->seedTelegramThread($day);
        $webThread = $this->seedWebThread($day);

        app(SupportDailyRollupAggregator::class)->aggregateDate($day);
        $written = app(WebSupportRollupAggregator::class)->aggregateDate($day);

        $this->assertSame(1, $written);

        $telegramRollup = SupportDailyRollup::query()->telegramSide()
            ->whereDate('conversation_date', $day)->sole();
        $webRollup = SupportDailyRollup::query()->webSide()
            ->whereDate('conversation_date', $day)->sole();

        // Веб-строка описана через тред, а не через TG-чат — и наоборот.
        $this->assertNull($webRollup->telegram_support_chat_id);
        $this->assertSame($webThread->id, $webRollup->support_conversation_id);
        $this->assertSame(SupportDailyRollup::CHANNEL_WEB, $webRollup->channel);
        $this->assertNull($telegramRollup->support_conversation_id);
        $this->assertSame(SupportDailyRollup::CHANNEL_TELEGRAM, $telegramRollup->channel);

        // Метрики считает один и тот же код: 2 входящих / 1 человеческий ответ
        // в каждом канале, первый ответ через 300 с.
        foreach ([$telegramRollup, $webRollup] as $rollup) {
            $this->assertSame(2, $rollup->incoming_count, $rollup->channel);
            $this->assertSame(1, $rollup->outgoing_count, $rollup->channel);
            $this->assertSame(1, $rollup->human_reply_count, $rollup->channel);
            $this->assertSame(300, $rollup->first_response_seconds, $rollup->channel);
            $this->assertFalse($rollup->is_unanswered, $rollup->channel);
        }

        // Единый отчёт: верхние суммы — по обоим каналам, разбивка рядом,
        // без ручной сверки двух источников.
        $packet = app(SupportDashboardPacketBuilder::class)->build($day);
        $today = $packet['summary']['today'];

        $this->assertSame(2, $today['conversations']);
        $this->assertSame(4, $today['incoming']);
        $this->assertSame(2, $today['outgoing']);
        $this->assertSame(2, $today['human_replies']);
        $this->assertSame(
            [SupportDailyRollup::CHANNEL_TELEGRAM, SupportDailyRollup::CHANNEL_WEB],
            collect(array_keys($today['by_channel']))->sort()->values()->all(),
        );
        $this->assertSame(2, $today['by_channel'][SupportDailyRollup::CHANNEL_WEB]['incoming']);
        $this->assertSame(2, $today['by_channel'][SupportDailyRollup::CHANNEL_TELEGRAM]['incoming']);

        // Обе строки рисуются одним списком — на веб-строке нет ->chat, и до
        // H1837 этот вызов был бы null-fatal.
        $this->assertCount(2, $packet['chats']);
        $this->assertContains(
            'Кабинет',
            collect($packet['chats'])->pluck('channel_label')->all(),
        );
    }

    public function test_unresolved_after_hours_kpi_applies_identically_to_both_channels(): void
    {
        $day = '2026-07-18';

        // Ни в одном канале на входящее не ответили; порог 24 ч давно истёк.
        $chat = $this->telegramChat(4242);
        $this->telegramMessage($chat, 1, 'incoming', $day.' 09:00:00');

        $thread = SupportConversation::create([
            'guest_token' => 'guest-overdue',
            'status' => SupportConversation::STATUS_OPEN,
        ]);
        $this->webMessage($thread, null, 'user', $day.' 09:00:00');

        app(SupportDailyRollupAggregator::class)->aggregateDate($day);
        app(WebSupportRollupAggregator::class)->aggregateDate($day);

        $this->assertSame(
            2,
            SupportDailyRollup::query()->whereDate('conversation_date', $day)
                ->where('unresolved_after_hours', true)->count(),
        );

        // Ответ в пределах порога снимает KPI — тоже одинаково в двух каналах.
        $freshDay = now(config('app.timezone'))->toDateString();
        $freshChat = $this->telegramChat(4343);
        $this->telegramMessage($freshChat, 1, 'incoming', now()->subMinutes(30));
        $this->telegramMessage($freshChat, 2, 'outgoing', now()->subMinutes(10));

        $freshThread = SupportConversation::create([
            'guest_token' => 'guest-fresh',
            'status' => SupportConversation::STATUS_OPEN,
        ]);
        $this->webMessage($freshThread, null, 'user', now()->subMinutes(30));
        $this->webMessage($freshThread, null, 'curator', now()->subMinutes(10));

        app(SupportDailyRollupAggregator::class)->aggregateDate($freshDay);
        app(WebSupportRollupAggregator::class)->aggregateDate($freshDay);

        $this->assertSame(
            0,
            SupportDailyRollup::query()->whereDate('conversation_date', $freshDay)
                ->where('unresolved_after_hours', true)->count(),
        );
    }

    public function test_web_rollups_are_classified_by_the_same_topic_rules(): void
    {
        SupportTopicRule::create([
            'category' => 'billing',
            'keywords' => ['оплат', 'цена'],
            'priority' => 10,
            'is_enabled' => true,
        ]);

        $day = '2026-07-19';
        $thread = SupportConversation::create([
            'guest_token' => 'guest-billing',
            'status' => SupportConversation::STATUS_OPEN,
        ]);
        $this->webMessage($thread, null, 'user', $day.' 10:00:00', 'Какая цена курса?');

        app(WebSupportRollupAggregator::class)->aggregateDate($day);

        $rollup = SupportDailyRollup::query()->webSide()->sole();

        $this->assertDatabaseHas('support_topic_assignments', [
            'support_daily_rollup_id' => $rollup->id,
            'category' => 'billing',
            'source' => 'keyword',
        ]);
    }

    public function test_messages_without_a_thread_are_attributed_to_their_user_and_channel(): void
    {
        $day = '2026-07-21';
        $student = User::factory()->create();

        // VK-бот и TG-student-бот пишут chat_messages БЕЗ треда (H1200).
        $this->webMessage(null, $student, 'user', $day.' 08:00:00', 'Вопрос из ВК', 'vk');
        $this->webMessage(null, $student, 'bot', $day.' 08:01:00', 'Ответ бота', 'vk');
        $this->webMessage(null, $student, 'user', $day.' 09:00:00', 'Вопрос из TG-бота', 'telegram_bot');

        app(WebSupportRollupAggregator::class)->aggregateDate($day);

        $vk = SupportDailyRollup::query()->channel(SupportDailyRollup::CHANNEL_VK)->sole();
        $telegramBot = SupportDailyRollup::query()->channel(SupportDailyRollup::CHANNEL_TELEGRAM_BOT)->sole();

        $this->assertSame($student->id, $vk->web_user_id);
        $this->assertNull($vk->support_conversation_id);
        $this->assertSame(1, $vk->outgoing_count);
        $this->assertSame(1, $vk->incoming_count);
        $this->assertSame(1, $telegramBot->incoming_count);
        $this->assertTrue($telegramBot->is_unanswered);
        $this->assertSame($student->id, $telegramBot->web_user_id);
    }

    public function test_command_writes_nothing_while_the_feature_flag_is_off(): void
    {
        config(['features.support_web_rollups' => false]);

        $day = now(config('app.timezone'))->toDateString();
        $thread = SupportConversation::create([
            'guest_token' => 'guest-flag-off',
            'status' => SupportConversation::STATUS_OPEN,
        ]);
        $this->webMessage($thread, null, 'user', now()->subHour());

        $this->artisan('support:rollup-web')->assertExitCode(0);
        $this->assertSame(0, SupportDailyRollup::query()->webSide()->count());

        $this->artisan('support:rollup-web', ['--force' => true, '--date' => $day])->assertExitCode(0);
        $this->assertSame(1, SupportDailyRollup::query()->webSide()->count());
    }

    public function test_aggregation_is_idempotent(): void
    {
        $day = '2026-07-22';
        $thread = SupportConversation::create([
            'guest_token' => 'guest-idempotent',
            'status' => SupportConversation::STATUS_OPEN,
        ]);
        $this->webMessage($thread, null, 'user', $day.' 10:00:00');

        app(WebSupportRollupAggregator::class)->aggregateDate($day);
        app(WebSupportRollupAggregator::class)->aggregateDate($day);

        $this->assertSame(1, SupportDailyRollup::query()->webSide()->count());
    }

    // --- helpers -----------------------------------------------------------

    private function seedTelegramThread(string $day): TelegramSupportChat
    {
        $chat = $this->telegramChat(1001);

        $this->telegramMessage($chat, 1, 'incoming', $day.' 09:00:00', 'Как оплатить?');
        $this->telegramMessage($chat, 2, 'outgoing', $day.' 09:05:00', 'Сейчас подскажу.', 'human');
        $this->telegramMessage($chat, 3, 'incoming', $day.' 09:30:00', 'Спасибо!');

        return $chat;
    }

    private function seedWebThread(string $day): SupportConversation
    {
        $student = User::factory()->create();
        $curator = User::factory()->create();

        $thread = SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->webMessage($thread, $student, 'user', $day.' 09:00:00', 'Как оплатить?');
        $this->webMessage($thread, $student, 'curator', $day.' 09:05:00', 'Сейчас подскажу.', null, $curator);
        $this->webMessage($thread, $student, 'user', $day.' 09:30:00', 'Спасибо!');

        return $thread;
    }

    private function telegramChat(int $telegramChatId): TelegramSupportChat
    {
        return TelegramSupportChat::create([
            'telegram_chat_id' => $telegramChatId,
            'type' => 'private',
            'title' => 'Chat '.$telegramChatId,
        ]);
    }

    private function telegramMessage(
        TelegramSupportChat $chat,
        int $messageId,
        string $direction,
        mixed $sentAt,
        string $text = 'текст',
        ?string $responderType = null,
    ): TelegramSupportMessage {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support'], ['is_enabled' => true]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $messageId,
            'direction' => $direction,
            'responder_type' => $responderType,
            'text' => $text,
            'sent_at' => $sentAt,
        ]);
    }

    private function webMessage(
        ?SupportConversation $thread,
        ?User $user,
        string $role,
        mixed $createdAt,
        string $text = 'текст',
        ?string $source = null,
        ?User $answeredBy = null,
    ): ChatMessage {
        $message = ChatMessage::create([
            'support_conversation_id' => $thread?->id,
            'user_id' => $user?->id,
            'role' => $role,
            'answered_by' => $answeredBy?->id,
            'text' => $text,
            'is_read' => false,
            'source' => $source,
        ]);

        // created_at задаётся явно: у веб-стороны нет отдельного sent_at.
        $message->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $message->refresh();
    }
}
