<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H6644 — идемпотентность отправителя согласий ПД в TG.
 *
 * Прод-дефект 13-10-2026: consent-like исходящие (LIKE '%соглас%') уходили
 * одному адресату ×2–4 за день на чатах 21/261/262/597/279. Каждая ветка
 * автоответчика имела свой cooldown 6 ч — за сутки окно истекает до четырёх
 * раз. Гейт в горловине постановки (SupportReplyService::createPendingOutgoing)
 * держит инвариант per-chat per-day ≤1 для ботовых исходящих; человек-куратор
 * и явное нажатие «Отправить» в полосе подсказок не гасятся.
 *
 * Доработка 13-11-2026: подтверждение «✅ Вы приняли политику
 * конфиденциальности / Оператор: ИП Гасунс Марцис» не содержит стема «соглас»
 * и потому проходило мимо гейта — в проде уходило две копии в одну минуту.
 * Теперь такие подтверждения гасятся «раз навсегда» на чат, а просьбы о
 * согласии остаются на прежнем суточном инварианте.
 */
class SupportConsentDailyDedupTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_TG_ID = 777_555_001;

    private TelegramSupportChat $chat;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        TelegramSupportAccount::query()->create(['name' => 'support']);

        $this->student = User::factory()->create();

        $this->chat = TelegramSupportChat::query()->create([
            'telegram_chat_id' => self::CHAT_TG_ID,
            'linked_user_id' => $this->student->id,
            'type' => 'private',
        ]);
    }

    private const CONSENT_TEXT_1 = 'Намасте! Для продолжения нужно ваше согласие на обработку персональных данных.';

    private const CONSENT_TEXT_2 = 'Пожалуйста, подтвердите согласие на обработку персональных данных — без него куратор не сможет отвечать.';

    /** Прод-текст от 13-11-2026 18:02: стема «соглас» в нём нет. */
    private const POLICY_CONFIRMATION = "✅ Вы приняли политику конфиденциальности\nОператор: ИП Гасунс Марцис";

    /** Другая формулировка того же подтверждения — тоже не должна уйти повторно. */
    private const POLICY_CONFIRMATION_2 = '✅ Политика конфиденциальности принята. Оператор: ИП Гасунс Марцис';

    public function test_second_consent_like_ai_send_same_day_is_deduped(): void
    {
        $replies = app(SupportReplyService::class);

        $first = $replies->queueAiReply($this->student, self::CONSENT_TEXT_1);
        $this->assertNotNull($first);

        // Другой текст, но тоже consent-like: вторая постановка обязана
        // вернуть СУЩЕСТВУЮЩУЮ строку, а не насоздавать дублей.
        $second = $replies->queueAiReply($this->student, self::CONSENT_TEXT_2);

        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->outgoingConsentCount());
    }

    public function test_non_consent_ai_sends_are_not_suppressed(): void
    {
        $replies = app(SupportReplyService::class);

        $replies->queueAiReply($this->student, 'Когда ближайшее занятие по грамматике?');
        $replies->queueAiReply($this->student, 'Запись занятия выложена в кабинете.');

        // Гейт трогает только consent-like тексты.
        $this->assertSame(2, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(0, $this->outgoingConsentCount());
    }

    public function test_bot_consent_send_is_suppressed_after_human_consent_send_today(): void
    {
        $replies = app(SupportReplyService::class);
        $curator = User::factory()->create();

        // Куратор сам отправил текст с «соглас» — студент его уже получил.
        $human = $replies->replyViaSupportChannel($this->student, self::CONSENT_TEXT_1, $curator);
        $this->assertNotNull($human);

        // Бот после этого второй копией не спамит.
        $bot = $replies->queueAiReply($this->student, self::CONSENT_TEXT_2);

        $this->assertNotNull($bot);
        $this->assertSame($human->id, $bot->id);
        $this->assertSame(1, $this->outgoingConsentCount());
    }

    public function test_human_replies_are_never_suppressed(): void
    {
        $replies = app(SupportReplyService::class);
        $curator = User::factory()->create();

        $first = $replies->replyViaSupportChannel($this->student, self::CONSENT_TEXT_1, $curator);
        $second = $replies->replyViaSupportChannel($this->student, self::CONSENT_TEXT_2, $curator);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $this->outgoingConsentCount());
    }

    public function test_next_day_allows_new_consent_send(): void
    {
        $replies = app(SupportReplyService::class);

        $first = $replies->queueAiReply($this->student, self::CONSENT_TEXT_1);
        $this->assertNotNull($first);

        Carbon::setTestNow(now()->addDay()->addHours(2));

        $nextDay = $replies->queueAiReply($this->student, self::CONSENT_TEXT_2);

        $this->assertNotNull($nextDay);
        $this->assertNotSame($first->id, $nextDay->id);
        $this->assertSame(2, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        Carbon::setTestNow();
    }

    public function test_hint_button_bypass_creates_second_row(): void
    {
        $replies = app(SupportReplyService::class);

        $first = $replies->queueAiReply($this->student, self::CONSENT_TEXT_1);
        $this->assertNotNull($first);

        // Явное нажатие куратором «Отправить» (SupportHintSendButton) идёт
        // с обходом гейта: решение человека молча гасить нельзя.
        $forced = $replies->queueAiReply($this->student, self::CONSENT_TEXT_2, null, null, true);

        $this->assertNotNull($forced);
        $this->assertNotSame($first->id, $forced->id);
        $this->assertSame(2, $this->outgoingConsentCount());
    }

    public function test_unlinked_dm_invite_is_deduped_too(): void
    {
        $replies = app(SupportReplyService::class);

        // Чат без привязанного юзера — путь queueUnlinkedDmMessage (H3542).
        $guestChat = TelegramSupportChat::query()->create([
            'telegram_chat_id' => self::CHAT_TG_ID + 1,
            'type' => 'private',
        ]);

        $first = $replies->queueUnlinkedDmMessage($guestChat, self::CONSENT_TEXT_1);
        $second = $replies->queueUnlinkedDmMessage($guestChat, self::CONSENT_TEXT_2);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
    }

    public function test_policy_confirmation_without_consent_stem_is_deduped(): void
    {
        $replies = app(SupportReplyService::class);

        // Две копии в одну минуту (прод-лог 13-11-2026 18:02).
        $first = $replies->queueAiReply($this->student, self::POLICY_CONFIRMATION);
        $second = $replies->queueAiReply($this->student, self::POLICY_CONFIRMATION);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->outgoingPolicyConfirmationCount());
        $this->assertSame(0, $this->outgoingConsentCount());
    }

    public function test_policy_confirmation_is_never_resent_on_a_later_day(): void
    {
        $replies = app(SupportReplyService::class);

        $first = $replies->queueAiReply($this->student, self::POLICY_CONFIRMATION);
        $this->assertNotNull($first);

        // Суточный лимит H6644 здесь недостаточен: согласие уже дано, через
        // три дня (и с другой формулировкой) подтверждать больше нечего.
        Carbon::setTestNow(now()->addDays(3));

        $later = $replies->queueAiReply($this->student, self::POLICY_CONFIRMATION_2);

        $this->assertNotNull($later);
        $this->assertSame($first->id, $later->id);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        Carbon::setTestNow();
    }

    public function test_confirmation_does_not_block_a_new_consent_request(): void
    {
        $replies = app(SupportReplyService::class);

        $confirmation = $replies->queueAiReply($this->student, self::POLICY_CONFIRMATION);
        $request = $replies->queueAiReply($this->student, self::CONSENT_TEXT_1);

        // Подтверждение и просьба о согласии считаются разными счётчиками:
        // расширение предиката не должно глушить обычные просьбы.
        $this->assertNotNull($confirmation);
        $this->assertNotNull($request);
        $this->assertNotSame($confirmation->id, $request->id);
        $this->assertSame(2, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        Carbon::setTestNow();
    }

    private function outgoingPolicyConfirmationCount(): int
    {
        return TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('text', 'like', '%политик%')
            ->count();
    }

    private function outgoingConsentCount(): int
    {
        return TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('text', 'like', '%соглас%')
            ->count();
    }
}
