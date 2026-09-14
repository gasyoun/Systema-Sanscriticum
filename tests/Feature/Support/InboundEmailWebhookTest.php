<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\InboundEmail;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Support\InboundEmailIngester;
use App\Support\UnifiedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3462: вебхук входящего email (zabota@samskrte.ru → проводник → Laravel).
 * Секрет пути (fail-closed), флаг OFF → 404, дедуп по Message-ID, очередь
 * нераспознанных отправителей, бейдж канала Email (зеркало H1200), троттлинг.
 */
class InboundEmailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-inbound-email-secret';

    private const URL = '/api/webhooks/inbound-email/'.self::SECRET;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_inbound_email' => true,
            'services.inbound_email.webhook_secret' => self::SECRET,
        ]);
    }

    /** @test */
    public function flag_off_returns_404(): void
    {
        config(['features.support_inbound_email' => false]);

        $this->postJson(self::URL, $this->payload())
            ->assertNotFound();
    }

    /** @test */
    public function rejects_without_valid_secret(): void
    {
        $this->postJson('/api/webhooks/inbound-email/wrong-secret', $this->payload())
            ->assertForbidden();

        // Секрет не сконфигурирован — эндпоинт выключен даже при совпадении пустот.
        config(['services.inbound_email.webhook_secret' => null]);

        $this->postJson(self::URL, $this->payload())
            ->assertForbidden();
    }

    /** @test */
    public function matched_sender_is_ingested_into_chat_messages_with_email_badge(): void
    {
        $user = User::factory()->create(['email' => 'student@example.com']);

        $this->postJson(self::URL, $this->payload(
            subject: 'Вопрос про оплату',
            text: 'Здравствуйте! Не пришёл доступ к курсу.',
        ))->assertOk()->assertJson(['ok' => true, 'status' => 'ingested']);

        $message = ChatMessage::query()->sole();
        $this->assertSame($user->id, $message->user_id);
        $this->assertSame('user', $message->role);
        $this->assertSame('email', $message->source);
        $this->assertFalse($message->is_read);
        $this->assertSame("Вопрос про оплату\n\nЗдравствуйте! Не пришёл доступ к курсу.", $message->text);

        // Threading на существующем conversation key.
        $thread = SupportConversation::query()->where('user_id', $user->id)->sole();
        $this->assertSame($thread->id, $message->support_conversation_id);

        // Квитанция помечена принятой и связана с сообщением.
        $inbound = InboundEmail::query()->sole();
        $this->assertSame(InboundEmail::STATUS_INGESTED, $inbound->status);
        $this->assertSame($user->id, $inbound->user_id);
        $this->assertSame($message->id, $inbound->chat_message_id);

        // Бейдж канала — зеркало H1200 VK/TG-бота.
        $unified = UnifiedMessage::fromChatMessage($message);
        $this->assertSame(UnifiedMessage::CHANNEL_EMAIL, $unified->channel);
        $this->assertSame('Email', $unified->channelLabel());
    }

    /** @test */
    public function duplicate_message_id_is_not_ingested_twice(): void
    {
        User::factory()->create(['email' => 'student@example.com']);
        $messageId = '<dup-check@mail.example>';

        $this->postJson(self::URL, $this->payload(messageId: $messageId))
            ->assertOk()
            ->assertJson(['status' => 'ingested']);

        // Ретрай проводника с тем же Message-ID — дубля нет.
        $this->postJson(self::URL, $this->payload(messageId: $messageId))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertDatabaseCount('inbound_emails', 1);
    }

    /** @test */
    public function unmatched_sender_is_queued_visibly_and_never_dropped(): void
    {
        $this->postJson(self::URL, $this->payload(from: 'stranger@example.com'))
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'queued']);

        $this->assertDatabaseCount('chat_messages', 0);

        $inbound = InboundEmail::query()->sole();
        $this->assertSame(InboundEmail::STATUS_QUEUED, $inbound->status);
        $this->assertSame('stranger@example.com', $inbound->from_email);
        $this->assertNull($inbound->user_id);
    }

    /** @test */
    public function message_id_angle_brackets_are_normalized_for_dedup(): void
    {
        User::factory()->create(['email' => 'student@example.com']);

        $this->postJson(self::URL, $this->payload(messageId: '<abc-123@mail.example>'))
            ->assertOk()
            ->assertJson(['status' => 'ingested']);

        $this->postJson(self::URL, $this->payload(messageId: 'abc-123@mail.example'))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('inbound_emails', 1);
    }

    /** @test */
    public function manual_link_from_queue_ingests_the_letter(): void
    {
        $this->postJson(self::URL, $this->payload(from: 'stranger@example.com'))
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        $user = User::factory()->create(['email' => 'real@example.com']);
        $inbound = InboundEmail::query()->sole();

        app(InboundEmailIngester::class)->linkToUser($inbound, $user);

        $message = ChatMessage::query()->sole();
        $this->assertSame($user->id, $message->user_id);
        $this->assertSame('email', $message->source);
        $this->assertSame(InboundEmail::STATUS_INGESTED, $inbound->refresh()->status);
    }

    /** @test */
    public function validation_errors_are_rejected(): void
    {
        $this->postJson(self::URL, ['from_email' => 'not-an-email'])
            ->assertStatus(422);

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    /** @test */
    public function endpoint_is_throttled(): void
    {
        config(['services.inbound_email.webhook_secret' => self::SECRET]);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson(self::URL, $this->payload(messageId: "throttle-$i@example"));
        }

        $this->postJson(self::URL, $this->payload(messageId: 'over-limit@example'))
            ->assertStatus(429);
    }

    /**
     * @param  string|null  ...  именованные перекрытия полей payload
     */
    private function payload(
        ?string $from = null,
        ?string $subject = null,
        ?string $text = null,
        ?string $messageId = null,
    ): array {
        return array_filter([
            'message_id' => $messageId ?? '<h3462-test-'.md5((string) mt_rand()).'@mail.example>',
            'from_email' => $from ?? 'student@example.com',
            'from_name' => 'Студент Тестов',
            'subject' => $subject,
            'text' => $text ?? 'Тело тестового письма.',
            'received_at' => '2026-08-24T12:00:00+00:00',
        ], fn ($value) => $value !== null);
    }
}
