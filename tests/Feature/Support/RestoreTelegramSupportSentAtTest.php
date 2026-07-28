<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Support\ChatTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `sent_at` нёс ON UPDATE current_timestamp: MySQL затирал реальную дату отправки
 * временем последнего ресинка (да ещё в UTC) — мартовские вопросы в Helpdesk
 * выглядели сегодняшними. Схему чинит миграция, данные — эта команда.
 */
class RestoreTelegramSupportSentAtTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSupportChat $chat;

    private TelegramSupportAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $account = TelegramSupportAccount::create(['name' => 'support']);
        $this->chat = TelegramSupportChat::create([
            'telegram_support_account_id' => $account->id,
            'telegram_chat_id' => -100777,
            'type' => 'supergroup',
            'title' => 'Чат учеников',
        ]);
    }

    private function message(int $tgId, string $storedSentAt, ?string $payloadSentAt, ?SupportConversation $thread = null): TelegramSupportMessage
    {
        return TelegramSupportMessage::create([
            'support_conversation_id' => $thread?->id,
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_chat_id' => $this->chat->telegram_chat_id,
            'telegram_message_id' => $tgId,
            'direction' => 'incoming',
            'text' => 'Вопрос №'.$tgId,
            'sent_at' => $storedSentAt,
            'raw_payload' => array_filter(['sent_at' => $payloadSentAt]),
        ]);
    }

    public function test_it_restores_the_real_date_from_the_payload(): void
    {
        $message = $this->message(1, '2026-07-28 11:12:15', '2026-03-15 13:57:30');

        $this->artisan('telegram:restore-sent-at')->assertSuccessful();

        $this->assertSame(
            '2026-03-15 13:57:30',
            $message->fresh()->sent_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_dry_run_changes_nothing(): void
    {
        $message = $this->message(2, '2026-07-28 11:12:15', '2026-03-15 13:57:30');

        $this->artisan('telegram:restore-sent-at', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(
            '2026-07-28 11:12:15',
            $message->fresh()->sent_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_correct_rows_and_rows_without_a_payload_date_are_left_alone(): void
    {
        $intact = $this->message(3, '2026-05-01 10:00:00', '2026-05-01 10:00:00');
        $noPayload = $this->message(4, '2026-07-28 11:12:15', null);

        $this->artisan('telegram:restore-sent-at')->assertSuccessful();

        $this->assertSame('2026-05-01 10:00:00', $intact->fresh()->sent_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-28 11:12:15', $noPayload->fresh()->sent_at->format('Y-m-d H:i:s'));
    }

    /** Мусорная дата в payload (эпоха, будущее) не должна ломать нормальную строку. */
    public function test_implausible_payload_dates_are_ignored(): void
    {
        $epoch = $this->message(5, '2026-07-28 11:12:15', '1970-01-01 00:00:00');
        $future = $this->message(6, '2026-07-28 11:12:15', CarbonImmutable::now()->addYear()->toDateTimeString());

        $this->artisan('telegram:restore-sent-at')->assertSuccessful();

        $this->assertSame('2026-07-28 11:12:15', $epoch->fresh()->sent_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-28 11:12:15', $future->fresh()->sent_at->format('Y-m-d H:i:s'));
    }

    /**
     * last_message_at собран из тех же испорченных значений: без пересчёта
     * мартовский тред так и висел бы вверху списка как сегодняшний.
     */
    public function test_it_recalculates_the_thread_last_message_at(): void
    {
        $thread = SupportConversation::create([
            'source_telegram_chat_id' => $this->chat->telegram_chat_id,
            'source_telegram_user_id' => 4242,
            'guest_name' => 'Ольга',
            'queue' => SupportConversation::QUEUE_TECHNICAL,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => '2026-07-28 11:12:15',
        ]);

        $this->message(7, '2026-07-28 11:12:15', '2026-03-15 13:57:30', $thread);
        $this->message(8, '2026-07-28 11:12:15', '2026-04-29 12:35:39', $thread);

        $this->artisan('telegram:restore-sent-at')->assertSuccessful();

        $this->assertSame(
            '2026-04-29 12:35:39',
            $thread->fresh()->last_message_at->format('Y-m-d H:i:s'),
        );
    }

    /** Повторный запуск не должен ничего трогать — команда идемпотентна. */
    public function test_running_twice_is_a_no_op(): void
    {
        $message = $this->message(9, '2026-07-28 11:12:15', '2026-03-15 13:57:30');

        $this->artisan('telegram:restore-sent-at')->assertSuccessful();
        $this->artisan('telegram:restore-sent-at')->assertSuccessful();

        $this->assertSame('2026-03-15 13:57:30', $message->fresh()->sent_at->format('Y-m-d H:i:s'));
    }

    /** Подпись под сообщением: дата появляется у всего, что не за сегодня. */
    public function test_timestamp_label_shows_a_date_for_older_messages(): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        $this->assertSame($now->format('H:i'), ChatTimestamp::label($now));
        $this->assertSame('вчера '.$now->subDay()->format('H:i'), ChatTimestamp::label($now->subDay()));
        $this->assertSame('', ChatTimestamp::label(null));

        $thisYear = $now->startOfYear()->addDays(10)->setTime(13, 57);
        $this->assertSame($thisYear->format('d.m').', 13:57', ChatTimestamp::label($thisYear));

        $old = CarbonImmutable::parse('2024-03-15 13:57:30', config('app.timezone'));
        $this->assertSame('15.03.2024, 13:57', ChatTimestamp::label($old));
        $this->assertSame('15.03.2024 13:57', ChatTimestamp::full($old));
    }
}
