<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\SupportObservability;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupportObservabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function page_is_hidden_when_feature_flag_is_off(): void
    {
        config(['features.support_observability' => false]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertFalse(SupportObservability::canAccess());
    }

    /** @test */
    public function page_is_hidden_for_non_admin_even_with_flag_on(): void
    {
        config(['features.support_observability' => true]);
        $student = User::factory()->create();
        $this->actingAs($student);

        $this->assertFalse(SupportObservability::canAccess());
    }

    /** @test */
    public function metrics_compute_and_page_renders_for_admin(): void
    {
        config(['features.support_observability' => true]);

        $account = TelegramSupportAccount::create([
            'name' => 'support-main',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subMinutes(3),
        ]);
        $staleAccount = TelegramSupportAccount::create([
            'name' => 'support-stale',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subHours(2),
            'last_sync_error' => 'FLOOD_WAIT',
        ]);

        $chat = TelegramSupportChat::create(['telegram_chat_id' => 777001]);

        // Доставка: 2 доставленных + 1 зависшее pending + 1 без трекинга.
        foreach ([
            ['telegram_message_id' => 1, 'raw_payload' => ['pending_delivery' => false, 'via' => 'helpdesk_unified_reply']],
            ['telegram_message_id' => 2, 'raw_payload' => ['pending_delivery' => false, 'via' => 'helpdesk_unified_reply']],
            ['telegram_message_id' => 3, 'raw_payload' => ['pending_delivery' => true, 'via' => 'helpdesk_unified_reply']],
            ['telegram_message_id' => 4, 'raw_payload' => null],
        ] as $fields) {
            TelegramSupportMessage::create($fields + [
                'telegram_support_account_id' => $account->id,
                'telegram_support_chat_id' => $chat->id,
                'telegram_chat_id' => $chat->telegram_chat_id,
                'direction' => 'outgoing',
                'role' => 'human',
                'sent_at' => now()->subDay(),
            ]);
        }

        SupportDailyRollup::create([
            'telegram_support_chat_id' => $chat->id,
            'conversation_date' => now()->subDay()->toDateString(),
            'incoming_count' => 5,
            'outgoing_count' => 4,
            'is_unanswered' => false,
            'first_response_seconds' => 600,
        ]);
        SupportDailyRollup::create([
            'telegram_support_chat_id' => $chat->id,
            'conversation_date' => now()->toDateString(),
            'incoming_count' => 2,
            'outgoing_count' => 0,
            'is_unanswered' => true,
        ]);

        SupportAiReplyEvent::create(['event_type' => 'suggested', 'meta' => []]);
        SupportAiReplyEvent::create(['event_type' => 'suggested', 'meta' => []]);
        SupportAiReplyEvent::create(['event_type' => 'answer_accepted', 'meta' => []]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $this->assertTrue(SupportObservability::canAccess());

        $report = (new SupportObservability)->report();

        $accounts = $report['accounts']->keyBy(fn (array $row) => $row['account']->name);
        $this->assertFalse($accounts['support-main']['is_stale']);
        $this->assertTrue($accounts['support-stale']['is_stale']);
        $this->assertNotNull($accounts['support-main']['lag_minutes']);

        $this->assertSame(4, $report['delivery']['outgoing_total']);
        $this->assertSame(3, $report['delivery']['tracked']);
        $this->assertSame(2, $report['delivery']['delivered']);
        $this->assertSame(1, $report['delivery']['pending']);
        $this->assertSame(66.7, $report['delivery']['rate']);

        $this->assertSame(2, $report['rollup']['conversations']);
        $this->assertSame(1, $report['rollup']['unanswered']);
        $this->assertSame(600, $report['rollup']['avg_first_response_seconds']);
        $this->assertSame(7, $report['rollup']['incoming']);
        $this->assertSame(4, $report['rollup']['outgoing']);

        $this->assertSame(3, $report['llm']['total']);
        $this->assertSame(2, $report['llm']['by_type']['suggested']);

        Livewire::test(SupportObservability::class)
            ->assertOk()
            ->assertSee('support-main')
            ->assertSee('support-stale')
            ->assertSee('FLOOD_WAIT');
    }
}
