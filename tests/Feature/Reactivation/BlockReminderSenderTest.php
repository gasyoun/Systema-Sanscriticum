<?php

declare(strict_types=1);

namespace Tests\Feature\Reactivation;

use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\DebtReminder;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramSupportChat;
use App\Models\User;
use App\Services\Reactivation\BlockReminderSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Мини-рассылка с экрана «Участники по блокам»: каналы, дедуп 24 ч и журнал.
 */
class BlockReminderSenderTest extends TestCase
{
    use RefreshDatabase;

    private function sender(): BlockReminderSender
    {
        return app(BlockReminderSender::class);
    }

    /** @test */
    public function sends_to_bot_and_email_and_journals_the_contact(): void
    {
        Mail::fake();
        Queue::fake();

        $user = User::factory()->create(['telegram_id' => 900001, 'email' => 'debtor@example.com']);

        $result = $this->sender()->send($user, 10, 3, 'Напоминание {name} про блок {block}', 'Тема', false, true, true);

        $this->assertSame('sent', $result['status']);
        $this->assertSame(['bot', 'email'], $result['channels']);

        Queue::assertPushed(SendMessengerAlerts::class, 1);
        Mail::assertQueued(DebtorReminderMail::class, 1);

        $this->assertDatabaseHas('debt_reminders', [
            'user_id' => $user->id,
            'course_id' => 10,
            'block_number' => 3,
            'source' => BlockReminderSender::SOURCE,
        ]);
    }

    /** @test */
    public function repeated_send_within_a_day_is_skipped(): void
    {
        Mail::fake();
        Queue::fake();

        $user = User::factory()->create(['telegram_id' => 900002, 'email' => 'again@example.com']);
        $sender = $this->sender();

        $first = $sender->send($user, 10, 3, 'Текст', 'Тема', false, true, true);
        $second = $sender->send($user, 10, 3, 'Текст', 'Тема', false, true, true);

        $this->assertSame('sent', $first['status']);
        $this->assertSame('skipped_recent', $second['status']);
        $this->assertSame(1, DebtReminder::query()->count());
        Queue::assertPushed(SendMessengerAlerts::class, 1);
    }

    /** @test */
    public function another_block_is_a_separate_reminder(): void
    {
        Mail::fake();
        Queue::fake();

        $user = User::factory()->create(['telegram_id' => 900003, 'email' => 'two@example.com']);
        $sender = $this->sender();

        $this->assertSame('sent', $sender->send($user, 10, 3, 'Блок 3', 'Тема', false, true, false)['status']);
        $this->assertSame('sent', $sender->send($user, 10, 4, 'Блок 4', 'Тема', false, true, false)['status']);
        $this->assertSame(2, DebtReminder::query()->count());
    }

    /** @test */
    public function account_channel_reaches_only_students_with_an_existing_chat(): void
    {
        Mail::fake();
        Queue::fake();
        config(['services.telegram_business.token' => 'bot-token']);

        TelegramBusinessConnection::create([
            'business_connection_id' => 'bc-test',
            'owner_telegram_user_id' => 111,
            'is_enabled' => true,
            'can_reply' => true,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200),
        ]);

        $withChat = User::factory()->create(['email' => 'chat@example.com']);
        TelegramSupportChat::create([
            'telegram_chat_id' => 4242,
            'linked_user_id' => $withChat->id,
            'type' => 'private',
        ]);
        $withoutChat = User::factory()->create(['email' => 'nochat@example.com']);

        $sender = $this->sender();

        $this->assertTrue($sender->channelsFor($withChat)['account']);
        $this->assertFalse($sender->channelsFor($withoutChat)['account']);

        $hit = $sender->send($withChat, 10, 2, 'Напоминание от аккаунта', 'Тема', true, false, false);
        $this->assertSame('sent', $hit['status']);
        $this->assertSame(['account'], $hit['channels']);

        $miss = $sender->send($withoutChat, 10, 2, 'Напоминание от аккаунта', 'Тема', true, false, false);
        $this->assertSame('no_channels', $miss['status']);
    }

    /** @test */
    public function student_without_any_channel_is_not_contacted(): void
    {
        Mail::fake();
        Queue::fake();

        $user = User::factory()->create(['telegram_id' => null, 'vk_id' => null, 'email' => 'nowhere@example.com']);

        // Запрошены только мессенджеры: почта не выбрана, бота и чата с
        // аккаунтом школы нет → отправить некуда.
        $result = $this->sender()->send($user, 10, 1, 'Текст', 'Тема', true, true, false);

        $this->assertSame('no_channels', $result['status']);
        $this->assertSame(0, DebtReminder::query()->count());
    }

    /** @test */
    public function source_fits_the_debt_reminders_column(): void
    {
        // 26-11-2026 прод ответил 500 «Data too long for column source»:
        // колонка была varchar(16), а источник — 18 символов. Миграция
        // 2026_11_26_160000_widen_debt_reminders_source_length расширила её до
        // 32; этот тест не даёт снова перерасти лимит молча.
        $this->assertLessThanOrEqual(32, mb_strlen(BlockReminderSender::SOURCE));
    }

    /** @test */
    public function failed_account_delivery_releases_the_journal_claim(): void
    {
        Mail::fake();
        Queue::fake();
        config(['services.telegram_business.token' => 'bot-token']);

        TelegramBusinessConnection::create([
            'business_connection_id' => 'bc-test',
            'owner_telegram_user_id' => 111,
            'is_enabled' => true,
            'can_reply' => true,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'forbidden'], 403),
        ]);

        $user = User::factory()->create(['email' => 'refused@example.com']);
        TelegramSupportChat::create([
            'telegram_chat_id' => 777001,
            'linked_user_id' => $user->id,
            'type' => 'private',
        ]);

        // Только канал аккаунта: Telegram отказал → отправки нет, клейм снят,
        // иначе следующий честный повтор был бы заблокирован дедупом.
        $result = $this->sender()->send($user, 10, 1, 'Текст', 'Тема', true, false, false);

        $this->assertSame('no_channels', $result['status']);
        $this->assertSame(0, DebtReminder::query()->count());
    }
}
