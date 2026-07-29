<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\WebSupportRollupAggregator;
use App\Services\TelegramSupport\SupportDailyRollupAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1837 follow-up — `support:topic-ranking` reads the channel split.
 *
 * The rollups became two-channel in the S10 pass; the ranking counted the new
 * rows automatically (it joins the rollups) but could not say WHERE a topic
 * arrives, and could not be sliced. Same deflection number, two very different
 * builds: 90 % web wants a page on the site, 90 % Telegram wants a bot answer.
 */
class SupportTopicRankingChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'features.support_web_rollups' => true,
        ]);

        SupportTopicRule::create([
            'category' => 'payment',
            'keywords' => ['оплат'],
            'priority' => 10,
            'is_enabled' => true,
        ]);
    }

    public function test_ranking_counts_both_channels_and_reports_where_the_topic_arrives(): void
    {
        $day = '2026-07-20';
        $this->seedBothChannels($day);

        $report = $this->ranking([]);
        $payment = collect($report['rows'])->firstWhere('category', 'payment');

        $this->assertSame('all', $report['channel']);
        $this->assertSame('rollup-weighted', $report['mode']);
        $this->assertSame(2, $payment['chat_days'], 'both the web thread and the TG chat must count');
        $this->assertSame(2, $payment['human_replies']);
        $this->assertEqualsWithDelta(0.5, $payment['web_share'], 0.001);
    }

    public function test_channel_slice_narrows_the_ranking(): void
    {
        $day = '2026-07-20';
        $this->seedBothChannels($day);

        $web = collect($this->ranking(['--channel' => 'web'])['rows'])->firstWhere('category', 'payment');
        $telegram = collect($this->ranking(['--channel' => 'telegram'])['rows'])->firstWhere('category', 'payment');

        $this->assertSame(1, $web['chat_days']);
        $this->assertSame(1, $telegram['chat_days']);
        $this->assertEqualsWithDelta(1.0, $web['web_share'], 0.001);
        $this->assertEqualsWithDelta(0.0, $telegram['web_share'], 0.001);
    }

    public function test_web_side_slice_covers_vk_and_the_student_bot_too(): void
    {
        $day = '2026-07-20';
        $this->seedBothChannels($day);
        // VK-бот пишет в те же chat_messages, но без треда — субъект тогда user_id.
        $vkStudent = User::factory()->create();
        $this->webMessage(null, $vkStudent, 'user', $day.' 12:00:00', 'Как оплатить курс?', 'vk');
        $this->webMessage(null, $vkStudent, 'curator', $day.' 12:04:00', 'Вот ссылка на оплату.', 'vk');

        app(WebSupportRollupAggregator::class)->aggregateDate($day);

        $webSide = collect($this->ranking(['--channel' => 'web-side'])['rows'])->firstWhere('category', 'payment');
        $telegram = collect($this->ranking(['--channel' => 'telegram'])['rows'])->firstWhere('category', 'payment');

        $this->assertSame(2, $webSide['chat_days'], 'widget thread + VK thread');
        $this->assertSame(1, $telegram['chat_days']);
    }

    public function test_unknown_channel_is_rejected_without_printing_a_ranking(): void
    {
        $this->artisan('support:topic-ranking --channel=carrier-pigeon')
            ->expectsOutputToContain('--channel must be one of:')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, string>  $options
     * @return array{channel: string, mode: string, rows: array<int, array<string, mixed>>}
     */
    private function ranking(array $options): array
    {
        Artisan::call('support:topic-ranking', $options + [
            '--since' => '2026-07-01',
            '--until' => '2026-07-31',
            '--json' => true,
        ]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedBothChannels(string $day): void
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support'], ['is_enabled' => true]);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 1001,
            'type' => 'private',
            'title' => 'Chat 1001',
        ]);

        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Как оплатить?',
            'sent_at' => $day.' 09:00:00',
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => 2,
            'direction' => 'outgoing',
            'responder_type' => 'human',
            'text' => 'Сейчас подскажу.',
            'sent_at' => $day.' 09:05:00',
        ]);

        $student = User::factory()->create();
        $curator = User::factory()->create();
        $thread = SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
        ]);
        $this->webMessage($thread, $student, 'user', $day.' 10:00:00', 'Как оплатить?');
        $this->webMessage($thread, $student, 'curator', $day.' 10:05:00', 'Сейчас подскажу.', null, $curator);

        app(SupportDailyRollupAggregator::class)->aggregateDate($day);
        app(WebSupportRollupAggregator::class)->aggregateDate($day);
    }

    private function webMessage(
        ?SupportConversation $thread,
        ?User $user,
        string $role,
        string $createdAt,
        string $text,
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

        $message->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $message->refresh();
    }
}
