<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\SendGroupChatInviteJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use App\Support\EnrollmentGroupLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 21-10-2026: новый студент пишет в личку, Telegram к кабинету не привязан.
 * Бот всё равно даёт ссылку на оплату группы — с подписанным контактом; гость
 * платит, контакт привязывается к плательщику, ссылка-заявка уходит в ту же
 * личку, и одобритель узнаёт студента по привязке.
 */
class EnrollUnlinkedContactTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '-1001234567890';

    private const TG = 571472245;

    private Course $course;

    private Group $group;

    private Tariff $tariff;

    private TelegramSupportAccount $account;

    private TelegramSupportChat $chat;

    private TelegramSupportContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00');
        Mail::fake();
        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_enroll_pay_link' => true,
            'features.grant_chosen_group' => true,
            'features.paid_chat_join_request' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        Http::fake([
            'api.telegram.org/botZAPISI-TOKEN/createChatInviteLink' => Http::response(['ok' => true, 'result' => ['invite_link' => 'https://t.me/+JOINLINK']]),
            'enter.tochka.com/*' => Http::response(['Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/abc', 'paymentLinkId' => 'tx_1']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->course = Course::factory()->create(['title' => 'Тест пилота записи', 'is_active' => true]);
        $this->tariff = Tariff::factory()->for($this->course)->block(1)->create(['price' => 10]);
        $this->group = Group::create(['name' => 'Тест пилота — воскресенье 23:00', 'status' => 'active', 'telegram_chat_id' => self::CHAT]);
        $this->course->groups()->attach($this->group->id);

        $this->account = TelegramSupportAccount::create(['name' => 'telegram-business', 'auto_reply_enabled' => true]);
        $this->chat = TelegramSupportChat::create(['telegram_chat_id' => self::TG, 'type' => 'private', 'last_message_at' => now()]);
        $this->contact = TelegramSupportContact::create([
            'telegram_user_id' => self::TG,
            'telegram_support_chat_id' => $this->chat->id,
            'name' => 'Новая студентка',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function incoming(string $text, ?string $at = null): TelegramSupportMessage
    {
        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_support_contact_id' => $this->contact->id,
            'telegram_chat_id' => self::TG,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => $at ?? now(),
        ]);
    }

    private function signedUrl(): string
    {
        return EnrollmentGroupLink::url($this->tariff, $this->group, $this->contact);
    }

    /** @return array<string, mixed> */
    private function formFields(): array
    {
        $query = [];
        parse_str((string) parse_url($this->signedUrl(), PHP_URL_QUERY), $query);

        return [
            'tariff_id' => $this->tariff->id,
            'group_id' => $this->group->id,
            'gsig' => $query['gsig'],
            'tgc' => $this->contact->id,
            'csig' => $query['csig'],
        ];
    }

    public function test_unlinked_contact_gets_pay_link_signed_for_contact(): void
    {
        $this->incoming('есть группа воскресенье 23:00?', '2026-10-21 15:58:00');
        $incoming = $this->incoming('запишите меня');

        $result = app(SupportDmAutoReply::class)->handle($incoming, null, 'private');

        $this->assertSame('sent', $result['status']);
        $out = TelegramSupportMessage::query()->where('direction', 'outgoing')->where('telegram_support_chat_id', $this->chat->id)->first();
        $this->assertNotNull($out);
        $this->assertStringContainsString('tgc='.$this->contact->id, (string) $out->text);
        $this->assertStringContainsString('csig=', (string) $out->text);
    }

    public function test_contact_linked_elsewhere_or_missing_gets_no_unlinked_link(): void
    {
        $this->contact->forceFill(['linked_user_id' => User::factory()->create()->id])->save();
        $this->incoming('есть группа воскресенье 23:00?', '2026-10-21 15:58:00');
        $incoming = $this->incoming('запишите меня');

        // Контакт уже привязан, а синк не передал юзера — ветку записи не трогаем.
        app(SupportDmAutoReply::class)->handle($incoming, null, 'private');

        $this->assertFalse(TelegramSupportMessage::query()->where('direction', 'outgoing')->where('text', 'like', '%tgc=%')->exists());
    }

    public function test_guest_checkout_stores_signed_contact_and_rejects_tampered(): void
    {
        Queue::fake();
        $guest = ['name' => 'Мария', 'surname' => 'Новикова', 'city' => 'Москва', 'email' => 'new-student@example.com'];

        $this->post(route('payment.create'), [...$this->formFields(), ...$guest]);
        $this->assertSame($this->contact->id, (int) Payment::query()->value('telegram_support_contact_id'));

        auth()->logout();
        Payment::query()->delete();
        $this->post(route('payment.create'), [...$this->formFields(), 'csig' => str_repeat('0', 32), ...$guest, 'email' => 'other@example.com']);
        $this->assertNull(Payment::query()->value('telegram_support_contact_id'));
    }

    public function test_checkout_page_carries_signed_contact(): void
    {
        $this->get($this->signedUrl())
            ->assertOk()
            ->assertSee('name="tgc"', false)
            ->assertSee('Тест пилота — воскресенье 23:00');
    }

    public function test_guest_with_existing_email_returns_to_same_link_after_login(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $this->post(route('payment.create'), [...$this->formFields(), 'name' => 'Мария', 'surname' => 'Н', 'city' => 'М', 'email' => 'known@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertSame($this->signedUrl(), session('url.intended'));
    }

    private function paidByGuest(): Payment
    {
        Queue::fake();
        $payer = User::factory()->create(['telegram_id' => null]);

        return Payment::create([
            'user_id' => $payer->id,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'telegram_support_contact_id' => $this->contact->id,
            'amount' => 10,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);
    }

    public function test_after_payment_contact_is_linked_and_join_link_goes_to_same_dm(): void
    {
        $this->incoming('запишите меня');
        $payment = $this->paidByGuest();

        app()->call([new SendGroupChatInviteJob($payment->id), 'handle']);

        $this->assertSame($payment->user_id, (int) $this->contact->fresh()->linked_user_id);
        $this->assertSame($payment->user_id, (int) $this->chat->fresh()->linked_user_id);
        $this->assertTrue(TelegramSupportMessage::query()
            ->where('telegram_support_chat_id', $this->chat->id)
            ->where('direction', 'outgoing')
            ->where('text', 'like', '%https://t.me/+JOINLINK%')
            ->exists());

        // Заявку в чат подаёт тот же Telegram — одобритель узнаёт его по привязке.
        (new ProcessTelegramZapisiUpdate([
            'update_id' => 777001,
            'chat_join_request' => [
                'chat' => ['id' => (int) self::CHAT, 'type' => 'supergroup'],
                'from' => ['id' => self::TG, 'first_name' => 'Мария'],
                'date' => now()->timestamp,
            ],
        ]))->handle();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest') && (string) $r['user_id'] === (string) self::TG);
    }

    public function test_contact_linked_to_someone_else_is_not_rebound(): void
    {
        $other = User::factory()->create();
        $this->contact->forceFill(['linked_user_id' => $other->id])->save();
        $this->incoming('запишите меня');
        $payment = $this->paidByGuest();

        app()->call([new SendGroupChatInviteJob($payment->id), 'handle']);

        $this->assertSame($other->id, (int) $this->contact->fresh()->linked_user_id);
        $this->assertFalse(TelegramSupportMessage::query()
            ->where('telegram_support_chat_id', $this->chat->id)
            ->where('direction', 'outgoing')
            ->where('text', 'like', '%JOINLINK%')
            ->exists());
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Telegram не привязан'));
    }
}
