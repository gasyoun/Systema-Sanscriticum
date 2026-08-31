<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3768 — живой автоответ студенту из FAQ, рулинг MG 31-08-2026 «только F».
 *
 * Это первая точка, где BM25-ответ уходит СТУДЕНТУ, а не куратору, поэтому
 * набор пинит не только счастливый путь, но и каждую границу, за которой
 * отправки быть не должно.
 */
class SupportDmLiveFaqAutoSendTest extends TestCase
{
    use RefreshDatabase;

    private const MATERIALS_QUESTION = 'куда загружать домашнее задание и в каком формате';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_auto_reply_live_faq' => true,
            'features.support_auto_reply_templates' => false,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            // Крошечный неподвижный корпус: живой faq.md меняется при каждом
            // экспорте из ORS-FAQ, и порог на нём был бы плавающим.
            'support.faq_rag.path' => base_path('tests/fixtures/faq_live_f_corpus.md'),
            'support.faq_rag.extra_paths' => [],
            'support.faq_rag.live_categories' => ['F'],
            'support.faq_rag.shadow_min_score' => 0.5,
            'support.faq_rag.shadow_min_score_by_category' => [],
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        // Аккаунт заводим ЗДЕСЬ, а не по ходу: тест про выключенный
        // auto_reply_enabled должен править существующую строку, иначе он
        // молча зеленел бы на пустой таблице.
        TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'auto_reply_enabled' => true,
        ]);
    }

    public function test_category_f_above_the_floor_is_answered_with_a_cited_faq_draft(): void
    {
        $user = $this->handleQuestion(self::MATERIALS_QUESTION);

        $sent = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertNotNull($sent, 'категория F выше порога обязана уходить студенту сама');
        $this->assertSame('faq_rag', $sent->meta['kind']);
        $this->assertSame('F', $sent->meta['category']);
        $this->assertGreaterThan(0.0, (float) $sent->meta['score']);
        $this->assertArrayHasKey('floor', $sent->meta, 'порог пишем в событие: иначе задним числом не проверить, чем он был');

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertStringContainsString(
            'Источник',
            (string) $outgoing->text,
            'рулинг R3: ответ студенту всегда несёт ссылку на раздел FAQ',
        );
        $this->assertSame($user->id, $outgoing->telegram_support_chat->linked_user_id ?? $user->id);
    }

    public function test_flag_off_keeps_the_answer_with_the_curator(): void
    {
        config(['features.support_dm_auto_reply_live_faq' => false]);

        $this->handleQuestion(self::MATERIALS_QUESTION);

        $this->assertSame(0, $this->sentCount(), 'при выключенном флаге поведение прежнее — только подсказка');
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
    }

    /** Рулинг «только F»: A/B/C остаются в тени, даже когда FAQ отвечает уверенно. */
    public function test_a_category_outside_live_categories_is_not_sent(): void
    {
        config(['support.faq_rag.live_categories' => []]);

        $this->handleQuestion(self::MATERIALS_QUESTION);

        $this->assertSame(0, $this->sentCount());
    }

    /**
     * Оборона вглубь: D и E запрещены рулингом R3 безусловно. Даже если кто-то
     * впишет их в конфиг, код обязан их вычеркнуть.
     */
    public function test_money_and_access_are_refused_even_when_configured_live(): void
    {
        config(['support.faq_rag.live_categories' => ['D', 'E', 'F']]);

        $this->handleQuestion('не могу оплатить блок, помогите с оплатой');
        $this->assertSame(0, $this->sentCount(), 'D (деньги) не уходит студенту ни при каком конфиге');

        $this->handleQuestion('не могу зайти в личный кабинет, забыла пароль', 9702);
        $this->assertSame(0, $this->sentCount(), 'E (доступы) не уходит студенту ни при каком конфиге');
    }

    public function test_score_below_the_floor_is_not_sent(): void
    {
        config(['support.faq_rag.shadow_min_score_by_category' => ['F' => 1000.0]]);

        $this->handleQuestion(self::MATERIALS_QUESTION);

        $this->assertSame(0, $this->sentCount(), 'ниже порога — молчим студенту, показываем куратору');
    }

    public function test_account_without_auto_reply_enabled_does_not_send(): void
    {
        TelegramSupportAccount::query()->update(['auto_reply_enabled' => false]);

        $this->handleQuestion(self::MATERIALS_QUESTION);

        $this->assertSame(0, $this->sentCount());
    }

    public function test_unlinked_student_does_not_send(): void
    {
        $this->handleQuestion(self::MATERIALS_QUESTION, 9703, linked: false);

        $this->assertSame(0, $this->sentCount());
    }

    /** H3765 A2: между потолками свежести студенту уже не отвечаем. */
    public function test_aged_question_is_not_sent(): void
    {
        $this->handleQuestion(self::MATERIALS_QUESTION, 9704, ageHours: 10);

        $this->assertSame(0, $this->sentCount());
    }

    private function sentCount(): int
    {
        return SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->count();
    }

    private function handleQuestion(
        string $text,
        int $chatId = 9701,
        bool $linked = true,
        int $ageHours = 0,
    ): User {
        $user = User::factory()->create();

        $account = TelegramSupportAccount::query()->where('name', 'support')->firstOrFail();

        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['linked_user_id' => $linked ? $user->id : null, 'last_message_at' => now()],
        );

        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now()->subHours($ageHours),
        ]);

        app(SupportDmAutoReply::class)->handle($incoming, $linked ? $user->id : null, 'private');

        return $user;
    }
}
