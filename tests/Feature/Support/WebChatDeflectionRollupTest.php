<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\SupportObservability;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\WebChatDailyRollupAggregator;
use App\Services\TelegramSupport\SupportDailyRollupAggregator;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1837 — паритет измерения дефлекции: веб-чат сворачивается в те же
 * `support_daily_rollups`, что и Telegram, и попадает в рейтинг тем.
 */
class WebChatDeflectionRollupTest extends TestCase
{
    use RefreshDatabase;

    private function conversationWithMessages(string $date): SupportConversation
    {
        $student = User::factory()->create();
        $curator = User::factory()->create();

        $conversation = SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => Carbon::parse($date.' 10:30'),
        ]);
        $conversation->forceFill(['created_at' => Carbon::parse($date.' 10:00')])->save();

        ChatMessage::create([
            'support_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Не приходит ссылка на зум, где расписание?',
            'is_read' => true,
        ])->forceFill(['created_at' => Carbon::parse($date.' 10:00')])->save();

        ChatMessage::create([
            'support_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'answered_by' => $curator->id,
            'role' => 'curator',
            'text' => 'Ссылка в кабинете',
            'is_read' => true,
        ])->forceFill(['created_at' => Carbon::parse($date.' 10:05')])->save();

        ChatMessage::create([
            'support_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'role' => 'bot',
            'text' => 'Черновик ИИ',
            'is_read' => true,
            'ai_state' => 'sent',
        ])->forceFill(['created_at' => Carbon::parse($date.' 10:07')])->save();

        return $conversation;
    }

    private function telegramChatWithMessages(string $date): TelegramSupportChat
    {
        $account = TelegramSupportAccount::create(['name' => 'support', 'is_enabled' => true]);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 4242, 'type' => 'private']);

        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 4242,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'role' => 'user',
            'text' => 'Когда занятие по зуму?',
            'sent_at' => Carbon::parse($date.' 11:00'),
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 4242,
            'telegram_message_id' => 2,
            'direction' => 'outgoing',
            'role' => 'curator',
            'responder_type' => 'human',
            'text' => 'В 19:00',
            'sent_at' => Carbon::parse($date.' 11:10'),
        ]);

        return $chat;
    }

    public function test_web_chat_day_is_rolled_up_with_telegram_parity_fields(): void
    {
        $date = '2026-07-20';
        $conversation = $this->conversationWithMessages($date);

        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);

        $rollup = SupportDailyRollup::web()->firstOrFail();

        $this->assertSame($conversation->id, $rollup->support_conversation_id);
        $this->assertNull($rollup->telegram_support_chat_id);
        $this->assertSame(SupportDailyRollup::CHANNEL_WEB, $rollup->channel);
        $this->assertSame(1, $rollup->incoming_count);
        $this->assertSame(2, $rollup->outgoing_count);
        // Куратор — человеческий ответ; сообщение бота (ai_state=sent) им не является.
        $this->assertSame(1, $rollup->human_reply_count);
        $this->assertSame(1, $rollup->ai_sent_count);
        $this->assertFalse($rollup->is_unanswered);
        $this->assertTrue($rollup->has_new_contact);
        $this->assertSame(300, $rollup->first_response_seconds);
    }

    public function test_rollup_is_idempotent_per_conversation_day(): void
    {
        $date = '2026-07-20';
        $this->conversationWithMessages($date);

        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);
        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);

        $this->assertSame(1, SupportDailyRollup::web()->count());
    }

    public function test_unanswered_web_thread_is_flagged(): void
    {
        $date = '2026-07-21';
        $student = User::factory()->create();
        $conversation = SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
        ]);
        ChatMessage::create([
            'support_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Есть кто?',
            'is_read' => false,
        ])->forceFill(['created_at' => Carbon::parse($date.' 09:00')])->save();

        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);

        $rollup = SupportDailyRollup::web()->firstOrFail();
        $this->assertTrue($rollup->is_unanswered);
        $this->assertNull($rollup->first_response_seconds);
    }

    public function test_topic_rules_classify_web_rollups_from_chat_messages(): void
    {
        $date = '2026-07-20';
        SupportTopicRule::create([
            'category' => 'zoom',
            'keywords' => ['зум'],
            'priority' => 10,
            'is_enabled' => true,
        ]);
        $this->conversationWithMessages($date);

        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);

        $rollup = SupportDailyRollup::web()->with('topicAssignments')->firstOrFail();
        $this->assertSame(['zoom'], $rollup->topicAssignments->pluck('category')->all());
    }

    public function test_topic_ranking_counts_both_channels_and_reports_the_web_share(): void
    {
        $date = '2026-07-20';
        SupportTopicRule::create([
            'category' => 'zoom',
            'keywords' => ['зум'],
            'priority' => 10,
            'is_enabled' => true,
        ]);

        $this->conversationWithMessages($date);
        $this->telegramChatWithMessages($date);

        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);
        app(SupportDailyRollupAggregator::class)->aggregateDate($date);

        $this->artisan('support:topic-ranking --since=2026-07-01 --until=2026-07-31 --json')
            ->assertExitCode(0);

        $rows = $this->rankingRows(['--since' => '2026-07-01', '--until' => '2026-07-31']);
        $zoom = collect($rows['rows'])->firstWhere('category', 'zoom');

        $this->assertSame('all', $rows['channel']);
        $this->assertSame('rollup-weighted', $rows['mode']);
        $this->assertSame(2, $zoom['chat_days'], 'both the web thread and the TG chat must be counted');
        $this->assertSame(2, $zoom['human_replies']);
        $this->assertEqualsWithDelta(0.5, $zoom['web_share'], 0.001);
    }

    public function test_channel_slice_narrows_the_ranking_to_one_channel(): void
    {
        $date = '2026-07-20';
        SupportTopicRule::create([
            'category' => 'zoom',
            'keywords' => ['зум'],
            'priority' => 10,
            'is_enabled' => true,
        ]);

        $this->conversationWithMessages($date);
        $this->telegramChatWithMessages($date);
        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);
        app(SupportDailyRollupAggregator::class)->aggregateDate($date);

        $web = $this->rankingRows(['--since' => '2026-07-01', '--until' => '2026-07-31', '--channel' => 'web']);
        $telegram = $this->rankingRows(['--since' => '2026-07-01', '--until' => '2026-07-31', '--channel' => 'telegram']);

        $this->assertSame(1, collect($web['rows'])->firstWhere('category', 'zoom')['chat_days']);
        $this->assertSame(1, collect($telegram['rows'])->firstWhere('category', 'zoom')['chat_days']);
        $this->assertEqualsWithDelta(1.0, collect($web['rows'])->firstWhere('category', 'zoom')['web_share'], 0.001);
        $this->assertEqualsWithDelta(0.0, collect($telegram['rows'])->firstWhere('category', 'zoom')['web_share'], 0.001);
    }

    public function test_unknown_channel_is_rejected(): void
    {
        $this->artisan('support:topic-ranking --channel=carrier-pigeon')
            ->expectsOutput('--channel must be one of: all, telegram, web')
            ->assertExitCode(1);
    }

    public function test_telegram_only_dashboard_ignores_web_rollups(): void
    {
        $date = '2026-07-20';
        $this->conversationWithMessages($date);
        $this->telegramChatWithMessages($date);
        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);
        app(SupportDailyRollupAggregator::class)->aggregateDate($date);

        // Без скоупа по каналу пакет фаталил бы на $rollup->chat->telegram_chat_id.
        $packet = app(SupportDashboardPacketBuilder::class)->build($date);

        $this->assertSame(1, $packet['summary']['today']['conversations']);
        $this->assertCount(1, $packet['chats']);
        $this->assertSame(4242, $packet['chats'][0]['chat_id']);
    }

    public function test_command_aggregates_a_single_date_when_the_flag_is_on(): void
    {
        config(['features.web_chat_deflection_rollup' => true]);
        $date = '2026-07-20';
        $this->conversationWithMessages($date);

        $this->artisan('support:rollup-web --date='.$date)
            ->assertExitCode(0);

        $this->assertSame(1, SupportDailyRollup::web()->count());
    }

    public function test_command_is_a_no_op_while_the_flag_is_off(): void
    {
        // Дефолт флага — OFF; проверяем именно дефолт, не подставленное значение.
        $date = '2026-07-20';
        $this->conversationWithMessages($date);

        $this->artisan('support:rollup-web --date='.$date)
            ->assertExitCode(0);

        $this->assertSame(0, SupportDailyRollup::count());
    }

    public function test_force_overrides_the_flag_for_a_one_off_backfill(): void
    {
        $date = '2026-07-20';
        $this->conversationWithMessages($date);

        $this->artisan('support:rollup-web --force --date='.$date)
            ->assertExitCode(0);

        $this->assertSame(1, SupportDailyRollup::web()->count());
    }

    public function test_both_channels_on_the_same_day_produce_two_rollup_rows(): void
    {
        $date = '2026-07-20';
        $this->conversationWithMessages($date);
        $this->telegramChatWithMessages($date);

        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);
        app(SupportDailyRollupAggregator::class)->aggregateDate($date);

        $this->assertSame(2, SupportDailyRollup::whereDate('conversation_date', $date)->count());
        $this->assertSame(1, SupportDailyRollup::web()->count());
        $this->assertSame(1, SupportDailyRollup::telegram()->count());
    }

    public function test_observability_page_reports_both_channels_side_by_side(): void
    {
        $date = now()->toDateString();
        $this->conversationWithMessages($date);
        $this->telegramChatWithMessages($date);
        app(WebChatDailyRollupAggregator::class)->aggregateDate($date);
        app(SupportDailyRollupAggregator::class)->aggregateDate($date);

        $report = (new SupportObservability)->report();

        $this->assertSame(1, $report['rollup']['conversations']);
        $this->assertSame(1, $report['web_rollup']['conversations']);
        $this->assertSame(1, $report['web_rollup']['incoming']);
        $this->assertSame(2, $report['web_rollup']['outgoing']);
    }

    /**
     * @param  array<string, string>  $options
     * @return array{channel: string, mode: string, rows: array<int, array<string, mixed>>}
     */
    private function rankingRows(array $options): array
    {
        Artisan::call('support:topic-ranking', $options + ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }
}
