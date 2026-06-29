<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TelegramSupportAnalytics;
use App\Models\SupportConversation;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramSupportAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_is_idempotent_and_builds_daily_support_metrics(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        SupportTopicRule::create([
            'category' => 'billing',
            'keywords' => ['оплат', 'цена'],
            'priority' => 10,
            'is_enabled' => true,
        ]);

        $curator = User::factory()->create(['name' => 'Masha', 'role' => Roles::ADMIN]);
        $student = User::factory()->create(['telegram_id' => 5001]);

        $payload = [
            [
                'telegram_chat_id' => 1001,
                'telegram_message_id' => 1,
                'telegram_user_id' => 5001,
                'contact_name' => 'Student One',
                'direction' => 'incoming',
                'text' => 'Какая цена и как пройти оплату?',
                'sent_at' => '2026-06-28 09:00:00',
            ],
            [
                'telegram_chat_id' => 1001,
                'telegram_message_id' => 2,
                'direction' => 'outgoing',
                'responder_user_id' => $curator->id,
                'responder_marker' => 'favicon:masha',
                'text' => 'Здравствуйте, помогу с оплатой.',
                'sent_at' => '2026-06-28 09:05:00',
            ],
            [
                'telegram_chat_id' => 1002,
                'telegram_message_id' => 1,
                'telegram_user_id' => 5002,
                'contact_name' => 'Student Two',
                'direction' => 'incoming',
                'text' => 'Когда стартует курс?',
                'sent_at' => '2026-06-28 10:00:00',
            ],
            [
                'telegram_chat_id' => 1003,
                'telegram_message_id' => 1,
                'telegram_user_id' => 5003,
                'direction' => 'incoming',
                'text' => 'Нужна программа курса',
                'sent_at' => '2026-06-28 11:00:00',
            ],
            [
                'telegram_chat_id' => 1003,
                'telegram_message_id' => 2,
                'direction' => 'outgoing',
                'ai_state' => 'sent',
                'text' => 'Вот краткая программа курса.',
                'sent_at' => '2026-06-28 11:01:00',
            ],
            [
                'telegram_chat_id' => 1001,
                'telegram_message_id' => 3,
                'telegram_user_id' => 5001,
                'direction' => 'incoming',
                'text' => 'Спасибо!',
                'sent_at' => '2026-06-29 12:00:00',
            ],
        ];

        $sync = app(TelegramSupportSyncService::class);
        $sync->syncNormalizedMessages($payload);
        $sync->syncNormalizedMessages($payload);

        $this->assertSame(6, TelegramSupportMessage::count());

        $conversation = SupportConversation::whereDate('conversation_date', '2026-06-28')
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', 1001))
            ->firstOrFail();

        $this->assertSame($student->id, $conversation->chat->linked_user_id);
        $this->assertSame(1, $conversation->incoming_count);
        $this->assertSame(1, $conversation->outgoing_count);
        $this->assertSame(1, $conversation->human_reply_count);
        $this->assertSame(300, $conversation->first_response_seconds);
        $this->assertTrue($conversation->has_new_contact);
        $this->assertFalse($conversation->is_unanswered);
        $this->assertDatabaseHas('support_topic_assignments', [
            'support_conversation_id' => $conversation->id,
            'category' => 'billing',
            'source' => 'keyword',
        ]);

        $this->assertDatabaseHas('support_conversations', [
            'conversation_date' => '2026-06-28 00:00:00',
            'is_unanswered' => true,
        ]);
        $this->assertDatabaseHas('support_conversations', [
            'conversation_date' => '2026-06-28 00:00:00',
            'ai_sent_count' => 1,
        ]);
        $this->assertDatabaseHas('support_topic_assignments', [
            'category' => 'uncategorized',
            'reason' => 'no keyword match; llm fallback reserved',
        ]);

        $todayConversation = SupportConversation::whereDate('conversation_date', '2026-06-29')->firstOrFail();
        $this->assertFalse($todayConversation->has_new_contact);
    }

    public function test_dashboard_packet_and_filament_filters_expose_support_metrics(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 2001,
                'telegram_message_id' => 1,
                'telegram_user_id' => 7001,
                'direction' => 'incoming',
                'text' => 'Есть вопрос',
                'sent_at' => '2026-06-28 09:00:00',
            ],
            [
                'telegram_chat_id' => 2002,
                'telegram_message_id' => 1,
                'telegram_user_id' => 7002,
                'direction' => 'incoming',
                'text' => 'Другой вопрос',
                'sent_at' => '2026-06-28 10:00:00',
            ],
            [
                'telegram_chat_id' => 2002,
                'telegram_message_id' => 2,
                'direction' => 'outgoing',
                'ai_state' => 'suggested',
                'text' => 'AI draft',
                'sent_at' => '2026-06-28 10:01:00',
            ],
        ]);

        $packet = app(SupportDashboardPacketBuilder::class)->build('2026-06-28');

        $this->assertSame('dashboard.live', $packet['packet']);
        $this->assertSame('telegram_support.analytics', $packet['kind']);
        $this->assertSame(2, $packet['summary']['today']['conversations']);
        $this->assertSame(1, $packet['summary']['today']['unanswered']);
        $this->assertSame(1, $packet['summary']['today']['ai_suggested']);
        $this->assertNotEmpty($packet['chats']);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $teacher = User::factory()->create(['role' => Roles::TEACHER]);

        $this->actingAs($teacher);
        $this->assertFalse(TelegramSupportAnalytics::canAccess());

        $component = Livewire::actingAs($admin)
            ->test(TelegramSupportAnalytics::class)
            ->set('selectedDate', '2026-06-28')
            ->set('onlyUnanswered', true);

        $this->assertCount(1, $component->instance()->conversations);
        $component->assertSee('Unanswered');
    }
}
