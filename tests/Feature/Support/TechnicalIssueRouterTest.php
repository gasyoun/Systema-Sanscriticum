<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportReplyService;
use App\Services\Support\TechnicalIssueDetector;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TechnicalIssueRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'support_tech.assignee_user_id' => null,
            'support_tech.keywords' => ['кабинет', 'zoom', 'доступ', 'пароль'],
            'services.telegram_support.enabled' => false,
            'features.support_unified_reply' => false,
        ]);
    }

    public function test_detector_matches_keywords_and_mention(): void
    {
        $detector = app(TechnicalIssueDetector::class);

        $this->assertTrue($detector->matches([
            'direction' => 'incoming',
            'text' => 'Не открывается кабинет',
        ]));
        $this->assertTrue($detector->matches([
            'direction' => 'incoming',
            'text' => 'привет',
            'mentioned_bot' => true,
        ]));
        $this->assertTrue($detector->matches([
            'direction' => 'incoming',
            'text' => 'а?',
            'reply_to_bot' => true,
        ]));
        $this->assertFalse($detector->matches([
            'direction' => 'incoming',
            'text' => 'Спасибо за урок!',
        ]));
        $this->assertFalse($detector->matches([
            'direction' => 'outgoing',
            'text' => 'кабинет открыт',
        ]));
    }

    public function test_private_dm_keyword_opens_technical_and_assigns(): void
    {
        $tech = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create(['telegram_id' => '5001']);
        config(['support_tech.assignee_user_id' => $tech->id]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 5001,
                'telegram_message_id' => 11,
                'telegram_user_id' => 5001,
                'direction' => 'incoming',
                'text' => 'Не работает кабинет',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'private',
                'contact_name' => 'Student',
            ],
        ]);

        $thread = SupportConversation::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($thread);
        $this->assertSame(SupportConversation::QUEUE_TECHNICAL, $thread->queue);
        $this->assertSame($tech->id, $thread->assigned_to);
        $this->assertSame(5001, (int) $thread->source_telegram_chat_id);
        $this->assertSame(11, (int) $thread->source_telegram_message_id);
    }

    public function test_private_dm_without_keyword_is_general(): void
    {
        $student = User::factory()->create(['telegram_id' => '5002']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 5002,
                'telegram_message_id' => 12,
                'telegram_user_id' => 5002,
                'direction' => 'incoming',
                'text' => 'Когда следующее занятие?',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'private',
            ],
        ]);

        $thread = SupportConversation::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($thread);
        $this->assertSame(SupportConversation::QUEUE_GENERAL, $thread->queue);
        $this->assertNull($thread->assigned_to);
    }

    public function test_group_without_tech_signal_does_not_open_thread(): void
    {
        $student = User::factory()->create(['telegram_id' => '6001']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100111,
                'telegram_message_id' => 21,
                'telegram_user_id' => 6001,
                'direction' => 'incoming',
                'text' => 'Спасибо за урок, всем хорошего дня',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'chat_title' => 'Учебная группа',
            ],
        ]);

        $this->assertDatabaseHas('telegram_support_messages', [
            'telegram_chat_id' => -100111,
            'telegram_message_id' => 21,
        ]);
        $this->assertSame(0, SupportConversation::query()->where('user_id', $student->id)->count());

        $chat = TelegramSupportChat::query()->where('telegram_chat_id', -100111)->first();
        $this->assertNotNull($chat);
        $this->assertNull($chat->linked_user_id);
    }

    public function test_group_keyword_opens_technical_on_contact_link(): void
    {
        $tech = User::factory()->create();
        $student = User::factory()->create(['telegram_id' => '6002']);
        config(['support_tech.assignee_user_id' => $tech->id]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100222,
                'telegram_message_id' => 22,
                'telegram_user_id' => 6002,
                'direction' => 'incoming',
                'text' => 'Не открывается zoom',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'chat_title' => 'Учебная группа 2',
            ],
        ]);

        $thread = SupportConversation::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($thread);
        $this->assertSame(SupportConversation::QUEUE_TECHNICAL, $thread->queue);
        $this->assertSame($tech->id, $thread->assigned_to);
        $this->assertSame(-100222, (int) $thread->source_telegram_chat_id);
        $this->assertSame('supergroup', $thread->source_chat_type);
    }

    public function test_group_mention_opens_technical(): void
    {
        $student = User::factory()->create(['telegram_id' => '6003']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100333,
                'telegram_message_id' => 23,
                'telegram_user_id' => 6003,
                'direction' => 'incoming',
                'text' => '@supportbot помоги',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'group',
                'mentioned_bot' => true,
            ],
        ]);

        $thread = SupportConversation::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($thread);
        $this->assertSame(SupportConversation::QUEUE_TECHNICAL, $thread->queue);
    }

    /**
     * Техвопрос от непривязанного автора раньше молча терялся: роутер требовал
     * users-запись, хотя ни приём, ни ответ её не используют. Теперь заводится
     * тред БЕЗ user_id — по образцу гостей веб-виджета.
     */
    public function test_unlinked_sender_opens_thread_without_user(): void
    {
        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100777,
                'telegram_message_id' => 31,
                'telegram_user_id' => 7001,
                'direction' => 'incoming',
                'text' => 'Не работает кабинет',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'contact_name' => 'Ольга',
                'chat_title' => 'Хинди гр.1',
            ],
        ]);

        $thread = SupportConversation::query()->whereNull('user_id')->first();

        $this->assertNotNull($thread);
        $this->assertSame(SupportConversation::QUEUE_TECHNICAL, $thread->queue);
        $this->assertSame(-100777, (int) $thread->source_telegram_chat_id);
        $this->assertSame(7001, (int) $thread->source_telegram_user_id);
        // Куратору сразу видно, кто и откуда спрашивает.
        $this->assertSame('Ольга (Хинди гр.1)', $thread->displayName());
    }

    /** Болтовню незнакомца в личке по-прежнему игнорируем — иначе тред на каждого. */
    public function test_unlinked_non_technical_private_message_is_ignored(): void
    {
        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 7002,
                'telegram_message_id' => 32,
                'telegram_user_id' => 7002,
                'direction' => 'incoming',
                'text' => 'привет, а вы кто?',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'private',
            ],
        ]);

        $this->assertSame(0, SupportConversation::query()->count());
        $this->assertDatabaseHas('telegram_support_messages', [
            'telegram_chat_id' => 7002,
            'telegram_message_id' => 32,
        ]);
    }

    /** В общем чате пишут разные люди — их вопросы не должны слипаться в один тред. */
    public function test_different_authors_in_one_chat_get_separate_threads(): void
    {
        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100888,
                'telegram_message_id' => 41,
                'telegram_user_id' => 8001,
                'direction' => 'incoming',
                'text' => 'не открывается кабинет',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'contact_name' => 'Первый',
            ],
            [
                'telegram_chat_id' => -100888,
                'telegram_message_id' => 42,
                'telegram_user_id' => 8002,
                'direction' => 'incoming',
                'text' => 'у меня тоже zoom не грузится',
                'sent_at' => now()->addMinute()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'contact_name' => 'Второй',
            ],
        ]);

        $this->assertSame(2, SupportConversation::query()->whereNull('user_id')->count());
    }

    /**
     * Очередь «Техника» была немой: вопрос ложился во вкладку и ждал, пока туда
     * заглянут. Теперь при переходе треда в эту очередь летит колокольчик.
     */
    public function test_new_technical_issue_notifies_the_assignee(): void
    {
        $tech = User::factory()->create(['role' => Roles::ADMIN]);
        config(['support_tech.assignee_user_id' => $tech->id]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100121,
                'telegram_message_id' => 61,
                'telegram_user_id' => 12001,
                'direction' => 'incoming',
                'text' => 'не открывается кабинет со вчера',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'contact_name' => 'Пётр',
            ],
        ]);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $tech->id]);
    }

    /** Колокольчик — один на тред, а не на каждое следующее сообщение. */
    public function test_following_messages_do_not_notify_again(): void
    {
        $tech = User::factory()->create(['role' => Roles::ADMIN]);
        config(['support_tech.assignee_user_id' => $tech->id]);

        foreach ([71, 72, 73] as $i => $messageId) {
            app(TelegramSupportSyncService::class)->syncNormalizedMessages([
                [
                    'telegram_chat_id' => -100122,
                    'telegram_message_id' => $messageId,
                    'telegram_user_id' => 12002,
                    'direction' => 'incoming',
                    'text' => 'zoom не грузится',
                    'sent_at' => now()->addMinutes($i)->toDateTimeString(),
                    'chat_type' => 'supergroup',
                    'contact_name' => 'Мария',
                ],
            ]);
        }

        $this->assertSame(1, \DB::table('notifications')->where('notifiable_id', $tech->id)->count());
    }

    /** Ответ в тред без привязки уходит юзерботом в исходный чат. */
    public function test_reply_to_unlinked_thread_goes_to_the_source_chat(): void
    {
        Queue::fake();
        config(['services.telegram_support.enabled' => true]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100123,
                'telegram_message_id' => 81,
                'telegram_user_id' => 12003,
                'direction' => 'incoming',
                'text' => 'не могу войти в кабинет',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'contact_name' => 'Игорь',
            ],
        ]);

        $thread = SupportConversation::query()->whereNull('user_id')->firstOrFail();
        $curator = User::factory()->create(['role' => Roles::ADMIN]);

        $sent = app(SupportReplyService::class)->replyToUnlinkedThread($thread, 'Сбросили пароль, проверьте почту', $curator);

        $this->assertNotNull($sent);
        $this->assertSame(-100123, (int) $sent->telegram_chat_id);
        $this->assertSame('outgoing', $sent->direction);
        // Ответ привязан к тому же треду и ждёт доставки ближайшим заходом синка.
        $this->assertSame($thread->id, (int) $sent->support_conversation_id);
        $this->assertTrue((bool) ($sent->raw_payload['pending_delivery'] ?? false));
    }

    /** Второе сообщение того же автора — тот же тред, без дубля. */
    public function test_second_message_from_same_author_reuses_the_thread(): void
    {
        foreach ([51, 52] as $i => $messageId) {
            app(TelegramSupportSyncService::class)->syncNormalizedMessages([
                [
                    'telegram_chat_id' => -100999,
                    'telegram_message_id' => $messageId,
                    'telegram_user_id' => 9001,
                    'direction' => 'incoming',
                    'text' => 'пароль не подходит',
                    'sent_at' => now()->addMinutes($i)->toDateTimeString(),
                    'chat_type' => 'supergroup',
                    'contact_name' => 'Аня',
                ],
            ]);
        }

        $this->assertSame(1, SupportConversation::query()->whereNull('user_id')->count());
    }

    public function test_empty_assignee_still_sets_technical_queue(): void
    {
        $student = User::factory()->create(['telegram_id' => '5003']);
        config(['support_tech.assignee_user_id' => null]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 5003,
                'telegram_message_id' => 13,
                'telegram_user_id' => 5003,
                'direction' => 'incoming',
                'text' => 'пароль не подходит',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'private',
            ],
        ]);

        $thread = SupportConversation::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($thread);
        $this->assertSame(SupportConversation::QUEUE_TECHNICAL, $thread->queue);
        $this->assertNull($thread->assigned_to);
    }

    public function test_reply_uses_source_group_peer_and_reply_to(): void
    {
        Queue::fake();

        $student = User::factory()->create(['telegram_id' => '8001']);
        $curator = User::factory()->create();
        config([
            'support_tech.assignee_user_id' => $curator->id,
            'services.telegram_support.enabled' => true,
        ]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100888,
                'telegram_message_id' => 88,
                'telegram_user_id' => 8001,
                'direction' => 'incoming',
                'text' => 'Нет доступа в кабинет',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
            ],
        ]);

        // Private chat more recent should NOT steal the reply peer.
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $private = TelegramSupportChat::create([
            'telegram_chat_id' => 8001,
            'linked_user_id' => $student->id,
            'type' => 'private',
            'last_message_at' => now()->addHour(),
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $private->id,
            'telegram_chat_id' => 8001,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'hi',
            'sent_at' => now()->addHour(),
        ]);

        $outgoing = app(SupportReplyService::class)
            ->replyViaSupportChannel($student, 'Проверьте ссылку', $curator);

        $this->assertNotNull($outgoing);
        $this->assertSame(-100888, (int) $outgoing->telegram_chat_id);
        $this->assertSame(88, (int) ($outgoing->raw_payload['reply_to_msg_id'] ?? 0));
        // Ждёт доставки: увезёт ближайший заход синка, очередь тут не участвует.
        $this->assertTrue((bool) ($outgoing->raw_payload['pending_delivery'] ?? false));
    }
}
