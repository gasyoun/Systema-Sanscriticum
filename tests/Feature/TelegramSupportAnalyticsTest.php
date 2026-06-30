<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TelegramSupportAnalytics;
use App\Filament\Resources\TelegramSupportContactResource;
use App\Filament\Resources\TelegramSupportContactResource\Pages\EditTelegramSupportContact;
use App\Models\SupportConversation;
use App\Models\SupportResponderMapping;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramSupportAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Реальный путь синка инстанцирует danog\MadelineProto\Settings. Пакет —
     * опциональная зависимость (нужен PHP >= 8.2.14, на проде 8.1 не ставится),
     * поэтому без него эти тесты пропускаем.
     */
    private function skipWithoutMadelineProto(): void
    {
        if (! class_exists(\danog\MadelineProto\Settings::class)) {
            $this->markTestSkipped('danog/madelineproto не установлен (опциональная зависимость).');
        }
    }

    public function test_sync_command_reports_unconfigured_and_missing_client_without_failing_scheduler(): void
    {
        config([
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => null,
            'services.telegram_support.api_hash' => null,
        ]);

        $this->artisan('telegram-support:sync')
            ->expectsOutput('Telegram support sync: unconfigured; synced=0')
            ->assertExitCode(0);

        config([
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => 'Missing\\MadelineProto\\Client',
        ]);

        $this->artisan('telegram-support:sync')
            ->expectsOutput('Telegram support sync: missing_madelineproto; synced=0')
            ->assertExitCode(0);
    }

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
        SupportResponderMapping::create([
            'marker_label' => 'favicon:masha',
            'user_id' => $curator->id,
            'responder_type' => 'human',
            'is_active' => true,
        ]);

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
        $this->assertDatabaseHas('telegram_support_messages', [
            'telegram_chat_id' => 1001,
            'telegram_message_id' => 2,
            'responder_user_id' => $curator->id,
            'responder_type' => 'human',
        ]);
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

        SupportTopicRule::create([
            'category' => 'course',
            'keywords' => ['курс'],
            'priority' => 10,
            'is_enabled' => true,
        ]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 2001,
                'telegram_message_id' => 1,
                'telegram_user_id' => 7001,
                'direction' => 'incoming',
                'text' => 'Есть вопрос про курс',
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
            ->assertSee('course')
            ->set('topic', 'course')
            ->assertSee('Telegram chat 2001')
            ->set('onlyUnanswered', true);

        $this->assertCount(1, $component->instance()->conversations);
        $component->assertSee('Unanswered');
    }

    public function test_madelineproto_sync_uses_incremental_peer_cursor(): void
    {
        $this->skipWithoutMadelineProto();

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
            'services.telegram_support.history_limit' => 50,
            'services.telegram_support.dialog_limit' => 20,
            'services.telegram_support.profile_backfill_limit' => 20,
        ]);

        FakeMadelineProtoClient::$histories = [
            3001 => [
                ['id' => 1, 'date' => strtotime('2026-06-28 09:00:00'), 'message' => 'Первое', 'peer_id' => 3001, 'from_id' => ['user_id' => 9001]],
                ['id' => 2, 'date' => strtotime('2026-06-28 09:02:00'), 'message' => 'Ответ', 'peer_id' => 3001, 'out' => true],
            ],
        ];
        FakeMadelineProtoClient::$lastHistoryRequests = [];
        FakeMadelineProtoClient::$startFailures = [];
        FakeMadelineProtoClient::$startCalls = 0;

        $first = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame('ok', $first['status']);
        $this->assertSame(2, $first['synced']);
        $this->assertSame(2, TelegramSupportMessage::count());
        $this->assertSame(0, FakeMadelineProtoClient::$lastHistoryRequests[0]['min_id']);

        FakeMadelineProtoClient::$histories[3001][] = [
            'id' => 3,
            'date' => strtotime('2026-06-28 09:05:00'),
            'message' => 'Новое',
            'peer_id' => 3001,
            'from_id' => ['user_id' => 9001],
        ];
        FakeMadelineProtoClient::$lastHistoryRequests = [];

        $second = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame('ok', $second['status']);
        $this->assertSame(1, $second['synced']);
        $this->assertSame(3, TelegramSupportMessage::count());
        $this->assertSame(2, FakeMadelineProtoClient::$lastHistoryRequests[0]['min_id']);

        $account = TelegramSupportAccount::where('name', 'support')->firstOrFail();
        $this->assertSame(3, $account->sync_state['peers']['3001']['last_message_id']);
    }

    public function test_madelineproto_sync_retries_once_after_auth_restart(): void
    {
        $this->skipWithoutMadelineProto();

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
            'services.telegram_support.history_limit' => 50,
            'services.telegram_support.dialog_limit' => 20,
            'services.telegram_support.profile_backfill_limit' => 20,
        ]);

        FakeMadelineProtoClient::$histories = [
            4001 => [
                ['id' => 1, 'date' => strtotime('2026-06-28 10:00:00'), 'message' => 'После рестарта', 'peer_id' => 4001, 'from_id' => ['user_id' => 9002]],
            ],
        ];
        FakeMadelineProtoClient::$lastHistoryRequests = [];
        FakeMadelineProtoClient::$startFailures = ['AUTH_RESTART'];
        FakeMadelineProtoClient::$startCalls = 0;

        $result = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(2, FakeMadelineProtoClient::$startCalls);
        $this->assertSame(1, TelegramSupportMessage::count());
    }

    public function test_madelineproto_sync_limits_dialogs_per_run_to_avoid_flooding(): void
    {
        $this->skipWithoutMadelineProto();

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
            'services.telegram_support.history_limit' => 50,
            'services.telegram_support.dialog_limit' => 2,
            'services.telegram_support.profile_backfill_limit' => 20,
        ]);

        FakeMadelineProtoClient::$histories = [
            5001 => [['id' => 1, 'date' => strtotime('2026-06-28 11:00:00'), 'message' => 'Первый', 'peer_id' => 5001]],
            5002 => [['id' => 1, 'date' => strtotime('2026-06-28 11:01:00'), 'message' => 'Второй', 'peer_id' => 5002]],
            5003 => [['id' => 1, 'date' => strtotime('2026-06-28 11:02:00'), 'message' => 'Третий', 'peer_id' => 5003]],
        ];
        FakeMadelineProtoClient::$lastHistoryRequests = [];
        FakeMadelineProtoClient::$startFailures = [];
        FakeMadelineProtoClient::$startCalls = 0;

        $result = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['synced']);
        $this->assertSame([5001, 5002], array_column(FakeMadelineProtoClient::$lastHistoryRequests, 'peer'));
    }

    public function test_sync_auto_links_contact_and_chat_when_user_telegram_id_matches(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $student = User::factory()->create(['telegram_id' => 6001]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 6001,
                'telegram_message_id' => 1,
                'telegram_user_id' => 6001,
                'contact_name' => 'Known Student',
                'direction' => 'incoming',
                'text' => 'Здравствуйте',
                'sent_at' => '2026-06-28 12:00:00',
            ],
        ]);

        $chat = TelegramSupportChat::where('telegram_chat_id', 6001)->firstOrFail();
        $contact = TelegramSupportContact::where('telegram_user_id', 6001)->firstOrFail();

        $this->assertSame($student->id, $chat->linked_user_id);
        $this->assertSame($student->id, $contact->linked_user_id);
    }

    public function test_sync_does_not_fail_without_users_telegram_id_column(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn('telegram_id');
        });

        $result = app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 6101,
                'telegram_message_id' => 1,
                'telegram_user_id' => 6101,
                'direction' => 'incoming',
                'text' => 'Без колонки telegram_id',
                'sent_at' => '2026-06-28 12:10:00',
            ],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseHas('telegram_support_chats', [
            'telegram_chat_id' => 6101,
            'linked_user_id' => null,
        ]);
    }

    public function test_existing_manual_contact_link_is_not_overwritten_by_auto_link(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $autoMatched = User::factory()->create(['telegram_id' => 6201]);
        $manual = User::factory()->create(['telegram_id' => 6202]);

        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 6201,
            'linked_user_id' => $manual->id,
            'type' => 'private',
        ]);
        TelegramSupportContact::create([
            'telegram_user_id' => 6201,
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => $manual->id,
            'name' => 'Manual Link',
        ]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 6201,
                'telegram_message_id' => 1,
                'telegram_user_id' => 6201,
                'direction' => 'incoming',
                'text' => 'Не перетирать ручную связку',
                'sent_at' => '2026-06-28 12:20:00',
            ],
        ]);

        $this->assertSame($manual->id, $chat->refresh()->linked_user_id);
        $this->assertSame(
            $manual->id,
            TelegramSupportContact::where('telegram_user_id', 6201)->firstOrFail()->linked_user_id
        );
        $this->assertNotSame($autoMatched->id, $chat->linked_user_id);
    }

    public function test_admin_can_manually_link_support_contact_to_user(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create(['name' => 'Linked Student']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 6301,
            'type' => 'private',
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 6301,
            'telegram_support_chat_id' => $chat->id,
            'name' => 'Unmatched Telegram',
        ]);

        $this->actingAs($admin);
        $this->assertTrue(TelegramSupportContactResource::canAccess());

        Livewire::actingAs($admin)
            ->test(EditTelegramSupportContact::class, ['record' => $contact->getRouteKey()])
            ->set('data.linked_user_id', $student->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($student->id, $contact->refresh()->linked_user_id);
        $this->assertSame($student->id, $chat->refresh()->linked_user_id);
    }

    public function test_auto_linker_matches_unique_telegram_username_from_user_field(): void
    {
        $student = User::factory()->create([
            'name' => 'Username Student',
            'telegram_username' => 'known_student',
        ]);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 6401,
            'type' => 'private',
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 6401,
            'telegram_support_chat_id' => $chat->id,
            'username' => 'known_student',
        ]);

        $result = app(TelegramSupportSyncService::class)->syncNormalizedMessages([]);

        $this->assertSame(1, $result['auto_linked']);
        $this->assertSame($student->id, $contact->refresh()->linked_user_id);
        $this->assertSame($student->id, $chat->refresh()->linked_user_id);
    }

    public function test_auto_linker_does_not_match_by_full_name_without_telegram_identity(): void
    {
        $student = User::factory()->create(['name' => 'Exact Telegram Name']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 6501,
            'type' => 'private',
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 6501,
            'telegram_support_chat_id' => $chat->id,
            'name' => 'Exact Telegram Name',
        ]);

        $result = app(TelegramSupportSyncService::class)->syncNormalizedMessages([]);

        $this->assertSame(0, $result['auto_linked']);
        $this->assertNull($contact->refresh()->linked_user_id);
    }

    public function test_madelineproto_sync_enriches_contacts_from_history_users_payload(): void
    {
        $student = User::factory()->create([
            'name' => 'History User',
            'telegram_username' => 'history_user',
        ]);

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
            'services.telegram_support.history_limit' => 50,
            'services.telegram_support.dialog_limit' => 20,
            'services.telegram_support.profile_backfill_limit' => 20,
        ]);

        FakeMadelineProtoClient::$histories = [
            6601 => [
                ['id' => 1, 'date' => strtotime('2026-06-28 13:00:00'), 'message' => 'Из истории', 'peer_id' => 6601, 'from_id' => ['user_id' => 9601]],
            ],
        ];
        FakeMadelineProtoClient::$users = [
            6601 => [
                ['id' => 9601, 'first_name' => 'History', 'last_name' => 'User', 'username' => 'history_user'],
            ],
        ];
        FakeMadelineProtoClient::$lastHistoryRequests = [];
        FakeMadelineProtoClient::$startFailures = [];
        FakeMadelineProtoClient::$startCalls = 0;

        $result = app(TelegramSupportSyncService::class)->sync();

        $contact = TelegramSupportContact::where('telegram_user_id', 9601)->firstOrFail();
        $this->assertSame('History User', $contact->name);
        $this->assertSame('history_user', $contact->username);
        $this->assertSame(1, $result['auto_linked']);
        $this->assertSame($student->id, $contact->linked_user_id);
    }

    public function test_madelineproto_sync_backfills_existing_contact_profile_and_auto_links(): void
    {
        $student = User::factory()->create([
            'name' => 'Backfill Student',
            'telegram_username' => 'backfill_student',
        ]);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 6701,
            'type' => 'private',
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 9701,
            'telegram_support_chat_id' => $chat->id,
        ]);

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
            'services.telegram_support.history_limit' => 50,
            'services.telegram_support.dialog_limit' => 20,
            'services.telegram_support.profile_backfill_limit' => 20,
        ]);

        FakeMadelineProtoClient::$histories = [];
        FakeMadelineProtoClient::$users = [];
        FakeMadelineProtoClient::$profiles = [
            9701 => ['User' => ['id' => 9701, 'first_name' => 'Backfill', 'last_name' => 'Student', 'username' => 'backfill_student']],
        ];
        FakeMadelineProtoClient::$lastHistoryRequests = [];
        FakeMadelineProtoClient::$startFailures = [];
        FakeMadelineProtoClient::$startCalls = 0;

        $result = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['auto_linked']);
        $this->assertSame('Backfill Student', $contact->refresh()->name);
        $this->assertSame('backfill_student', $contact->username);
        $this->assertSame($student->id, $contact->linked_user_id);
        $this->assertSame($student->id, $chat->refresh()->linked_user_id);
    }

    public function test_madelineproto_sync_uses_private_peer_as_contact_user_id_when_from_id_is_missing(): void
    {
        $student = User::factory()->create(['telegram_id' => 6801]);

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
            'services.telegram_support.history_limit' => 50,
            'services.telegram_support.dialog_limit' => 20,
            'services.telegram_support.profile_backfill_limit' => 20,
        ]);

        FakeMadelineProtoClient::$histories = [
            6801 => [
                ['id' => 1, 'date' => strtotime('2026-06-28 13:00:00'), 'message' => 'Private without from_id', 'peer_id' => 6801],
            ],
        ];
        FakeMadelineProtoClient::$users = [];
        FakeMadelineProtoClient::$lastHistoryRequests = [];
        FakeMadelineProtoClient::$startFailures = [];
        FakeMadelineProtoClient::$startCalls = 0;

        $result = app(TelegramSupportSyncService::class)->sync();

        $contact = TelegramSupportContact::where('telegram_user_id', 6801)->firstOrFail();
        $this->assertSame(1, $result['synced']);
        $this->assertSame($student->id, $contact->linked_user_id);
        $this->assertSame($student->id, $contact->chat->linked_user_id);
    }

    public function test_import_students_writes_telegram_username_field_and_keeps_note_clean(): void
    {
        $path = $this->writeStudentImportCsv([
            implode(',', ['id', 'x', 'name', 'telegram', 'phone', 'email', 'vk', 'h', 'i', 'status', 'k', 'note']),
            implode(',', ['1', '', 'Import Student', '@imported_tg', '+79990000000', 'Import@Example.test', '', '', '', 'Обычный студент', '', 'Любит грамматику']),
        ]);

        $this->artisan('import:academy', ['--students-file' => $path])
            ->expectsChoice('Что будем импортировать сейчас?', '4. Студенты (готово)', $this->academyImportChoices())
            ->assertExitCode(0);

        $student = User::where('name', 'Import Student')->firstOrFail();
        $this->assertSame('imported_tg', $student->telegram_username);
        $this->assertSame('Комментарий: Любит грамматику', $student->note);
        $this->assertStringNotContainsString('Telegram:', (string) $student->note);
    }

    public function test_import_students_does_not_overwrite_existing_telegram_username_with_blank_csv(): void
    {
        User::factory()->create([
            'name' => 'Existing Student',
            'email' => 'existing@example.test',
            'telegram_username' => 'keep_me',
        ]);

        $path = $this->writeStudentImportCsv([
            implode(',', ['id', 'x', 'name', 'telegram', 'phone', 'email', 'vk', 'h', 'i', 'status', 'k', 'note']),
            implode(',', ['1', '', 'Existing Student', '', '+79990000001', 'existing@example.test', '', '', '', 'Обычный студент', '', 'Обновленная заметка']),
        ]);

        $this->artisan('import:academy', ['--students-file' => $path])
            ->expectsChoice('Что будем импортировать сейчас?', '4. Студенты (готово)', $this->academyImportChoices())
            ->assertExitCode(0);

        $student = User::where('name', 'Existing Student')->firstOrFail();
        $this->assertSame('keep_me', $student->telegram_username);
        $this->assertSame('Комментарий: Обновленная заметка', $student->note);
    }

    public function test_import_students_merges_by_existing_email_when_phone_confirms_identity(): void
    {
        $existing = User::factory()->create([
            'name' => 'Old Student Name',
            'email' => 'shared@example.test',
            'phone' => '+7 999 000 00 02',
            'telegram_username' => 'old_username',
        ]);

        $path = $this->writeStudentImportCsv([
            implode(',', ['id', 'x', 'name', 'telegram', 'phone', 'email', 'vk', 'h', 'i', 'status', 'k', 'note']),
            implode(',', ['1', '', 'New Student Name', '@new_username', '+79990000002', 'Shared@Example.test', '', '', '', 'Обычный студент', '', '']),
        ]);

        $this->artisan('import:academy', ['--students-file' => $path])
            ->expectsChoice('Что будем импортировать сейчас?', '4. Студенты (готово)', $this->academyImportChoices())
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $fresh = $existing->fresh();
        $this->assertSame('Old Student Name', $fresh->name);
        $this->assertSame('shared@example.test', $fresh->email);
        $this->assertSame('new_username', $fresh->telegram_username);
    }

    public function test_import_students_does_not_merge_same_email_for_different_identity_without_confirming_signal(): void
    {
        User::factory()->create([
            'name' => 'Existing Different Person',
            'email' => 'shared@example.test',
            'phone' => '+7 111 111 11 11',
            'telegram_username' => 'existing_person',
        ]);

        $path = $this->writeStudentImportCsv([
            implode(',', ['id', 'x', 'name', 'telegram', 'phone', 'email', 'vk', 'h', 'i', 'status', 'k', 'note']),
            implode(',', ['1', '', 'Actually Other Person', '@other_person', '+79990000003', 'shared@example.test', '', '', '', 'Обычный студент', '', '']),
        ]);

        $this->artisan('import:academy', ['--students-file' => $path])
            ->expectsChoice('Что будем импортировать сейчас?', '4. Студенты (готово)', $this->academyImportChoices())
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 2);
        $created = User::where('name', 'Actually Other Person')->firstOrFail();
        $this->assertStringEndsWith('@no-email.com', $created->email);
        $this->assertSame('other_person', $created->telegram_username);
    }

    public function test_backfills_telegram_usernames_from_legacy_notes_without_changing_notes(): void
    {
        $user = User::factory()->create([
            'telegram_username' => null,
            'note' => "Telegram: @legacy_user\nКомментарий: оставить",
        ]);

        $this->artisan('telegram:backfill-usernames-from-notes --apply')
            ->assertExitCode(0);

        $fresh = $user->fresh();
        $this->assertSame('legacy_user', $fresh->telegram_username);
        $this->assertSame("Telegram: @legacy_user\nКомментарий: оставить", $fresh->note);
    }

    /**
     * @return array<int, string>
     */
    private function academyImportChoices(): array
    {
        return [
            '1. Преподаватели (готово)',
            '2. Курсы, Тарифы и Группы (готово)',
            '3. Блоки (пока пропустим)',
            '4. Студенты (готово)',
            '5. Оплаты и Доступы',
            'Выход',
        ];
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function writeStudentImportCsv(array $lines): string
    {
        $path = storage_path('framework/testing/imports/students-'.uniqid().'.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }
}

class FakeMadelineProtoClient
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $histories = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $users = [];

    /** @var array<int, array<string, mixed>> */
    public static array $profiles = [];

    /** @var array<int, array<string, mixed>> */
    public static array $lastHistoryRequests = [];

    /** @var array<int, string> */
    public static array $startFailures = [];

    public static int $startCalls = 0;

    public object $messages;

    public function __construct(string $session, mixed $settings)
    {
        $this->messages = new class
        {
            /**
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            public function getHistory(array $params): array
            {
                FakeMadelineProtoClient::$lastHistoryRequests[] = $params;
                $peer = (int) $params['peer'];
                $minId = (int) $params['min_id'];

                return [
                    'messages' => collect(FakeMadelineProtoClient::$histories[$peer] ?? [])
                        ->filter(fn (array $message) => (int) $message['id'] > $minId)
                        ->values()
                        ->all(),
                    'users' => FakeMadelineProtoClient::$users[$peer] ?? [],
                ];
            }
        };
    }

    public function start(): void
    {
        self::$startCalls++;

        if (self::$startFailures !== []) {
            throw new \RuntimeException(array_shift(self::$startFailures));
        }
    }

    /**
     * @return array<int>
     */
    public function getDialogIds(): array
    {
        return array_keys(self::$histories);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInfo(int $id): array
    {
        return self::$profiles[$id] ?? [];
    }
}
