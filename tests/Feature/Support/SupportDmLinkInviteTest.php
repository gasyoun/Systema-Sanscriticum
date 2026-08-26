<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\MessageTemplate;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Support\SupportDmLinkInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3542: мост «незалинкованный партнёр → linked user» под флагом
 * support_dm_link_invite (default OFF). Незалинкованное свежее сообщение
 * распознанной категории получает ОДНО приглашение за cooldown-окно; email-
 * форма по capability-ссылке связывает контакт+чат; после этого бот отвечает
 * сам (dm_auto_sent); линкованный поток не меняется; флаг OFF = прежнее
 * поведение.
 */
class SupportDmLinkInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_link_invite' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    public function test_unlinked_fresh_message_invites_once_per_window(): void
    {
        [$account] = $this->makeContactScenario();

        $first = $this->incomingForContact($account, 'сколько стоит курс и как оплатить');
        $result = app(SupportDmAutoReply::class)->handle($first, null, 'private');

        $this->assertSame('invite_sent', $result['status']);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertSame(SupportDmLinkInvite::VIA, $outgoing->raw_payload['via']);
        $this->assertTrue((bool) ($outgoing->raw_payload['pending_delivery'] ?? false));
        $this->assertStringContainsString('/support/link/', (string) $outgoing->text);

        /** @var TelegramSupportContact $contact */
        $contact = TelegramSupportContact::query()->firstOrFail();
        $this->assertNotNull($contact->link_token_hash);
        $this->assertNotNull($contact->link_invited_at);
        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', SupportDmLinkInvite::EVENT_SENT)->count(),
        );

        // Второе сообщение в окне cooldown НЕ плодит второе приглашение.
        $second = $this->incomingForContact($account, 'и ещё раз: сколько стоит курс');
        $secondResult = app(SupportDmAutoReply::class)->handle($second, null, 'private');

        $this->assertSame('hinted', $secondResult['status']);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_flag_off_keeps_today_behavior(): void
    {
        config(['features.support_dm_link_invite' => false]);

        [$account] = $this->makeContactScenario();

        $result = app(SupportDmAutoReply::class)
            ->handle($this->incomingForContact($account, 'сколько стоит курс и как оплатить'), null, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        /** @var TelegramSupportContact $contact */
        $contact = TelegramSupportContact::query()->firstOrFail();
        $this->assertNull($contact->link_token_hash);
    }

    public function test_email_reply_links_contact_and_chat_and_unlocks_auto_reply(): void
    {
        [$account] = $this->makeContactScenario();

        // Шаг 1: приглашение на свежее незалинкованное сообщение; capability-
        // ссылку достаём из фактического текста DM — ровно как увидит студент.
        app(SupportDmAutoReply::class)
            ->handle($this->incomingForContact($account, 'сколько стоит курс и как оплатить'), null, 'private');

        /** @var TelegramSupportMessage $invite */
        $invite = TelegramSupportMessage::query()->where('direction', 'outgoing')->firstOrFail();
        $this->assertMatchesRegularExpression(
            '~https?://[^/\s]+/support/link/([A-Za-z0-9]+)~',
            (string) $invite->text,
            'В тексте приглашения должна быть полная ссылка /support/link/{token}',
        );
        $plaintext = (string) preg_replace(
            '~.*https?://[^/\s]+/support/link/([A-Za-z0-9]+).*~s',
            '$1',
            (string) $invite->text,
        );

        $user = User::factory()->create(['email' => 'student@example.com']);

        $get = $this->get('/support/link/'.$plaintext);
        $get->assertOk();
        $get->assertSee('Ваша почта');

        $post = $this->post('/support/link/'.$plaintext, ['email' => 'STUDENT@example.com']);
        $post->assertRedirect();

        /** @var TelegramSupportContact $contact */
        $contact = TelegramSupportContact::query()->firstOrFail();
        $contact->refresh();
        $this->assertSame($user->id, $contact->linked_user_id);

        $chat = TelegramSupportChat::query()->findOrFail($contact->telegram_support_chat_id);
        $this->assertSame($user->id, $chat->linked_user_id);

        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', 'dm_contact_linked')->count(),
        );

        // GET по погашенной ссылке теперь спокойно показывает успех (без формы).
        $this->get('/support/link/'.$plaintext)
            ->assertOk()
            ->assertSee('Готово');

        // Шаг 2: следующий вопрос того же партнёра получает настоящий автоответ.
        // Приглашение «старим» за пределы ack-cooldown, как это бывает в жизни:
        // связывание случается спустя часы после приглашения.
        TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->update(['sent_at' => now()->subHours(7)]);

        config(['features.support_auto_reply_templates' => true]);
        MessageTemplate::query()->create([
            'title' => 'Поддержка · тест D',
            'body' => "Намасте, {name}!\n\nТестовый шаблон оплаты.",
            'category' => MessageTemplate::CATEGORY_SUPPORT,
            'suggester_category' => 'D',
            'is_active' => true,
        ]);

        $next = $this->incomingForContact($account, 'как оплатить курс из другого города');
        $reply = app(SupportDmAutoReply::class)->handle($next, $user->id, 'private');

        $this->assertSame('sent', $reply['status']);

        $sentEvent = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->first();
        $this->assertNotNull($sentEvent, 'dm_auto_sent должен появиться после связывания');
    }

    public function test_unknown_email_creates_passwordless_user_uniformly(): void
    {
        [$account, $contact] = $this->makeContactScenario();

        app(SupportDmAutoReply::class)
            ->handle($this->incomingForContact($account, 'сколько стоит курс и как оплатить'), null, 'private');

        $plaintext = $contact->refresh()->issueLinkToken(336);

        $before = User::query()->count();
        $this->followingRedirects()
            ->post('/support/link/'.$plaintext, ['email' => 'New.Reader@example.com'])
            ->assertOk()
            ->assertSee('Готово');

        $this->assertSame($before + 1, User::query()->count());

        $created = User::query()->where('email', 'new.reader@example.com')->firstOrFail();
        $contact->refresh();
        $this->assertSame($created->id, $contact->linked_user_id);
    }

    public function test_linked_contact_never_gets_invite(): void
    {
        $account = TelegramSupportAccount::create(['name' => 'rusamskrtam', 'auto_reply_enabled' => true]);
        $user = User::factory()->create();
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9207,
            'linked_user_id' => $user->id,
            'type' => 'private',
            'last_message_at' => now(),
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 55001,
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => $user->id,
        ]);
        $seed = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => 1001,
            'direction' => 'incoming',
            'text' => 'первое сообщение для резолва аккаунта',
            'sent_at' => now()->subMinutes(5),
        ]);

        $incoming = $this->cloneIncoming($account, $chat, $contact, 'сколько стоит курс и как оплатить');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_backfill_is_bounded_idempotent_and_dry_safe(): void
    {
        $invites = app(SupportDmLinkInvite::class);

        [$account, $contact] = $this->makeContactScenario();

        // Вне окна свежести — приглашения не получит.
        $this->extraContact($account, daysOld: 60);

        // Dry: только счётчики, без записи.
        $this->artisan('support:send-link-invites', ['--dry' => true])
            ->expectsOutputToContain('[DRY]')
            ->assertExitCode(0);

        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        // Знаменатель: свежий незалинкованный в окне 30 дней ровно один.
        $census = $invites->census(30);
        $this->assertSame(1, $census['unlinked_recent']);
        $this->assertSame(1, $census['unlinked_recent_gated']);

        // Реальный прогон.
        $this->artisan('support:send-link-invites')
            ->expectsOutputToContain('ОТПРАВЛЕНО')
            ->assertExitCode(0);

        $outgoingCount = TelegramSupportMessage::query()->where('direction', 'outgoing')->count();
        $this->assertSame(1, $outgoingCount, 'приглашение уходит только свежему незалинкованному');

        $contact->refresh();
        $this->assertNotNull($contact->link_invited_at);

        // Идемпотентность: повторный прогон ничего не добавляет.
        $this->artisan('support:send-link-invites')->assertExitCode(0);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Сценарий «незалинкованный партнёр rusamskrtam»: аккаунт с гейтом,
     * private-чат без линка, контакт без юзера, сид-сообщение (для резолва
     * аккаунта сервисом).
     *
     * @return array{0: TelegramSupportAccount, 1: TelegramSupportContact}
     */
    private function makeContactScenario(): array
    {
        $account = TelegramSupportAccount::create(['name' => 'rusamskrtam', 'auto_reply_enabled' => true]);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9301,
            'type' => 'private',
            'last_message_at' => now(),
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 53001,
            'telegram_support_chat_id' => $chat->id,
            'name' => 'Читатель Канала',
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => 5001,
            'direction' => 'incoming',
            'text' => 'Намасте',
            'sent_at' => now()->subMinutes(10),
        ]);

        return [$account, $contact];
    }

    private function extraContact(TelegramSupportAccount $account, int $daysOld): TelegramSupportContact
    {
        static $n = 0;
        $n++;
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9400 + $n,
            'type' => 'private',
            'last_message_at' => now()->subDays($daysOld),
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 54000 + $n,
            'telegram_support_chat_id' => $chat->id,
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => 6000 + $n,
            'direction' => 'incoming',
            'text' => "старое сообщение {$n}",
            'sent_at' => now()->subDays($daysOld),
        ]);

        return $contact;
    }

    private function incomingForContact(TelegramSupportAccount $account, string $text): TelegramSupportMessage
    {
        /** @var TelegramSupportContact $contact */
        $contact = TelegramSupportContact::query()->firstOrFail();
        $chat = TelegramSupportChat::query()->findOrFail($contact->telegram_support_chat_id);

        return $this->cloneIncoming($account, $chat, $contact, $text);
    }

    private function cloneIncoming(
        TelegramSupportAccount $account,
        TelegramSupportChat $chat,
        TelegramSupportContact $contact,
        string $text,
    ): TelegramSupportMessage {
        static $seq = 7000;

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $seq++,
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }
}
