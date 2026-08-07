<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\PaymentPromise;
use App\Models\SupportConversation;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportParityReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2382 — support:parity-report aggregate acceptance packet.
 */
class SupportParityReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'features.support_web_rollups' => true,
            'features.support_visitor_geo' => true,
            'features.support_visitor_presence' => true,
            'features.support_lead_capture' => true,
            'features.support_unified_reply' => true,
            'features.support_ai_assist' => true,
            'features.support_observability' => true,
            'support_geo.driver' => 'null',
            'services.telegram_support.enabled' => true,
            'support.rollup.unresolved_after_hours' => 24,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-08-07 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_builder_emits_channel_zeros_and_hold_when_geo_driver_null(): void
    {
        $day = '2026-08-05';
        $account = TelegramSupportAccount::firstOrCreate(
            ['name' => 'support'],
            ['is_enabled' => true, 'last_successful_sync_at' => now()],
        );
        $account->forceFill([
            'is_enabled' => true,
            'last_successful_sync_at' => now(),
        ])->save();

        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => -1001,
            'type' => 'private',
            'title' => 'Tech canary',
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'ping',
            'sent_at' => now()->subDay(),
        ]);

        $rollup = SupportDailyRollup::create([
            'channel' => SupportDailyRollup::CHANNEL_TELEGRAM,
            'telegram_support_chat_id' => $chat->id,
            'conversation_date' => $day,
            'incoming_count' => 2,
            'outgoing_count' => 1,
            'human_reply_count' => 1,
            'is_unanswered' => false,
            'unresolved_after_hours' => false,
            'first_response_seconds' => 40,
        ]);

        SupportTopicAssignment::create([
            'support_daily_rollup_id' => $rollup->id,
            'category' => 'access',
            'source' => 'keyword',
            'confidence' => 1.0,
            'reason' => 'test',
        ]);

        SupportConversation::create([
            'user_id' => User::factory()->create()->id,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now()->subDays(2),
        ]);

        $course = \App\Models\Course::factory()->create();
        PaymentPromise::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'promised_at' => now()->subDay()->toDateString(),
            'amount' => 1000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        ChatMessage::create([
            'user_id' => User::factory()->create()->id,
            'role' => 'user',
            'text' => 'web hello',
            'source' => null,
            'is_read' => false,
        ]);

        $report = app(SupportParityReportBuilder::class)->build(14);

        $this->assertSame(14, $report['days']);
        $this->assertSame('none', $report['privacy']['exports']);
        $this->assertArrayHasKey('web', $report['channel_mix']);
        $this->assertArrayHasKey('vk', $report['channel_mix']);
        $this->assertSame(0, $report['channel_mix']['vk']['conversations']);
        $this->assertSame(1, $report['channel_mix']['telegram']['conversations']);
        $this->assertSame(2, $report['channel_mix']['telegram']['incoming']);
        $this->assertNotEmpty($report['topics']);
        $this->assertSame('access', $report['topics'][0]['category']);
        $this->assertArrayHasKey('topic_access_chat_days', $report['outcome_slices']);
        $this->assertGreaterThanOrEqual(1, $report['outcome_slices']['overdue_payment_promises_schoolwide']);
        $this->assertSame('HOLD', $report['verdict']['status']);
        $this->assertTrue(
            collect($report['verdict']['reasons'])->contains(
                fn (string $r) => str_contains($r, 'support_geo.driver null'),
            ),
        );
        $this->assertArrayHasKey('support_unified_reply', $report['rollback']);
        $this->assertArrayHasKey('email_channel', $report['rollback']);
    }

    public function test_artisan_command_json_exits_failure_on_hold(): void
    {
        $this->artisan('support:parity-report', ['--days' => 7, '--json' => true])
            ->assertFailed();
    }
}
