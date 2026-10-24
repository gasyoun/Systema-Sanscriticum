<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\Sufler\Ceilings;
use App\Services\Support\Sufler\SendPolicyGate;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * H5776: суфлёр, политика v1 — enforcement под гейтами.
 *
 * Каждый пункт verify_gates_before_external_action из policy/sufler.policy.yml
 * проверен тестом: нарушение гейта БЛОКИРУЕТ отправку и ПИШЕТ ТРЕЙС
 * (dm_policy_blocked), студенту молчание, куратору подсказка. Потолки
 * machinery (max_steps_per_ticket / tokens_per_ticket) авто-стопят серию
 * с трейсом dm_ceiling_stop.
 */
class SuflerSendPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 9701;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_auto_reply_live_faq' => false,
            'features.support_auto_reply_templates' => false,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'support.faq_rag.path' => base_path('tests/fixtures/faq_shadow_corpus.md'),
            'support.faq_rag.shadow_min_score' => 1000.0,
            'support.faq_rag.shadow_min_score_by_category' => [],
            'services.telegram_support.sufler_max_steps_per_ticket' => 8,
            'services.telegram_support.sufler_tokens_per_ticket' => null,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'auto_reply_enabled' => true,
        ]);
    }

    // ——— Гейт 1: money_amount_matches_system ———

    public function test_money_amount_in_template_draft_is_blocked(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Стоимость полного курса — 3 200 ₽. Оплата на сайте.', 'template', []);

        $this->assertContains(SendPolicyGate::GATE_MONEY, array_column($violations, 'gate'));
    }

    public function test_money_amount_in_system_facts_draft_is_allowed(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Ваш баланс после оплаты: −1 500 ₽, доступ активен.', 'facts', [
            'fact_type' => 'balance',
        ]);

        $this->assertSame([], $violations, 'системный резолвер фактов — единственный, кому разрешена сумма');
    }

    public function test_money_amount_in_llm_draft_is_blocked(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Курс стоит примерно 1500 руб.', 'llm_draft', [
            'faq_chunk_ids' => ['kb/01'],
        ]);

        $this->assertContains(SendPolicyGate::GATE_MONEY, array_column($violations, 'gate'));
    }

    // ——— Гейт 2: no_personal_data_in_corpus ———

    public function test_email_in_draft_is_blocked(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Напишите менеджеру: ivanov@example.com', 'greeting', []);

        $gates = array_column($violations, 'gate');
        $this->assertContains(SendPolicyGate::GATE_NO_PII, $gates);
        $this->assertTrue(
            str_contains(implode(' ', array_column($violations, 'detail')), 'email'),
            'трейс называет класс ПДн',
        );
    }

    public function test_ru_phone_in_draft_is_blocked(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Позвоните куратору: 8 912 345 67 89', 'greeting', []);

        $gates = array_column($violations, 'gate');
        $this->assertContains(SendPolicyGate::GATE_NO_PII, $gates);
        $this->assertTrue(
            str_contains(implode(' ', array_column($violations, 'detail')), 'phone_ru'),
            'трейс называет класс ПДн',
        );
    }

    public function test_zoom_link_and_dates_are_not_flagged_as_pii(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations(
            'Ссылка на занятие: https://zoom.us/j/12345678901 — занятие 03.10.2026, урок №12.',
            'facts',
            ['fact_type' => 'zoom'],
        );

        $this->assertSame([], $violations, 'легитимные факты LMS (zoom/даты) ПДн-гейт не душит');
    }

    // ——— Гейт 3: citation_present_for_every_fact ———

    public function test_faq_rag_without_citation_is_blocked(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Запись появится в кабинете через час.', 'faq_rag', []);

        $this->assertContains(SendPolicyGate::GATE_CITATION, array_column($violations, 'gate'));
    }

    public function test_faq_rag_with_chunk_id_is_clean(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Запись появится в кабинете через час.', 'faq_rag', [
            'chunk_id' => 'faq/recordings',
        ]);

        $this->assertSame([], $violations);
    }

    public function test_llm_draft_without_faq_chunk_ids_is_blocked(): void
    {
        $gate = new SendPolicyGate;

        $violations = $gate->violations('Загрузите работу в кабинет.', 'llm_draft', []);

        $this->assertContains(SendPolicyGate::GATE_CITATION, array_column($violations, 'gate'));
    }

    public function test_static_greeting_and_ack_are_clean(): void
    {
        $gate = new SendPolicyGate;

        $this->assertSame([], $gate->violations('Намасте! Рады вас видеть.', 'greeting', []));
        $this->assertSame([], $gate->violations('Получили сообщение, ответим в течение дня.', 'ack', []));
    }

    // ——— Send-path: блокировка + трейс + маршрут куратору ———

    public function test_send_auto_blocks_pii_draft_writes_trace_and_hints_curator(): void
    {
        [$user, $incoming] = $this->ticket();

        $result = $this->callSendAuto($incoming, $user, 'Наш менеджер: ivanov@example.com', 'template', []);

        $this->assertSame('hinted', $result['status'], 'нарушение гейта = маршрут куратору');
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'PII в исходящем черновике = студенту ничего не уходит',
        );

        $trace = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_POLICY_BLOCKED)
            ->firstOrFail();

        $this->assertSame('template', $trace->meta['kind']);
        $this->assertSame(SendPolicyGate::GATE_NO_PII, $trace->meta['gates'][0]['gate']);
        $this->assertStringContainsString('ivanov@example.com', (string) $trace->meta['draft_excerpt'], 'трейс несёт excerpt черновика');
    }

    public function test_send_auto_blocks_stale_money_amount_in_faq_draft(): void
    {
        [$user, $incoming] = $this->ticket();

        $result = $this->callSendAuto(
            $incoming,
            $user,
            'Цена блока — 4 500 ₽. Источник — наш FAQ.',
            'faq_rag',
            ['chunk_id' => 'faq/prices'],
        );

        $this->assertSame('hinted', $result['status']);

        $trace = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_POLICY_BLOCKED)
            ->firstOrFail();

        $this->assertSame(SendPolicyGate::GATE_MONEY, $trace->meta['gates'][0]['gate']);
    }

    // ——— Потолки machinery: авто-стоп с трейсом ———

    public function test_max_steps_ceiling_auto_stops_with_trace(): void
    {
        [$user, $incoming] = $this->ticket();

        for ($i = 0; $i < 8; $i++) {
            $outgoing = TelegramSupportMessage::create([
                'telegram_support_account_id' => $incoming->telegram_support_account_id,
                'telegram_support_chat_id' => $incoming->telegram_support_chat_id,
                'telegram_chat_id' => self::CHAT_ID,
                'telegram_message_id' => 500_000 + $i,
                'direction' => 'outgoing',
                'text' => 'автоответ '.$i,
                'sent_at' => now(),
            ]);

            SupportAiReplyEvent::create([
                'telegram_support_message_id' => $outgoing->id,
                'event_type' => SupportDmAutoReply::EVENT_SENT,
                'meta' => ['via' => SupportDmAutoReply::VIA, 'kind' => 'facts'],
            ]);
        }

        $result = $this->callSendAuto($incoming, $user, 'Чистый черновик без нарушений.', 'greeting', []);

        $this->assertSame('hinted', $result['status'], 'потолок = авто-стоп, вопрос куратору');
        $this->assertSame(
            8,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            '9-я автоотправка в окне не уходит',
        );

        $trace = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_CEILING_STOP)
            ->firstOrFail();

        $this->assertSame('max_steps_per_ticket', $trace->meta['reason']);
        $this->assertSame(24, $trace->meta['window_hours'], 'окно потолка из конфига (дефолт 24 ч)');
    }

    public function test_tokens_ceiling_auto_stops_llm_leg_when_configured(): void
    {
        config(['services.telegram_support.sufler_tokens_per_ticket' => 100]);

        [$user, $incoming] = $this->ticket();

        $outgoing = TelegramSupportMessage::create([
            'telegram_support_account_id' => $incoming->telegram_support_account_id,
            'telegram_support_chat_id' => $incoming->telegram_support_chat_id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => 600_001,
            'direction' => 'outgoing',
            'text' => 'llm ответ',
            'sent_at' => now(),
        ]);

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $outgoing->id,
            'event_type' => SupportDmAutoReply::EVENT_SENT,
            'meta' => [
                'via' => SupportDmAutoReply::VIA,
                'kind' => SupportDmAutoReply::KIND_LLM_DRAFT,
                'usage' => ['total_tokens' => 80],
            ],
        ]);

        $result = $this->callSendAuto($incoming, $user, 'Ещё один LLM ответ.', SupportDmAutoReply::KIND_LLM_DRAFT, [
            'faq_chunk_ids' => ['kb/01'],
            'usage' => ['total_tokens' => 50],
        ]);

        $this->assertSame('hinted', $result['status'], '80 предыдущих + 50 входящих > 100 = авто-стоп');

        $trace = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_CEILING_STOP)
            ->firstOrFail();

        $this->assertSame('tokens_per_ticket', $trace->meta['reason']);
    }

    public function test_tokens_ceiling_is_disabled_until_mg_fills_monthly_allowance(): void
    {
        $ceilings = new Ceilings;

        $this->assertNull(
            $ceilings->tokensPerTicket(),
            'monthly_allowance TBD = потолка токенов нет, флаги пилота OFF (политика v1)',
        );
        $this->assertSame(8, $ceilings->maxStepsPerTicket());
    }

    // ——— Фикстуры ———

    /**
     * @return array{0: User, 1: TelegramSupportMessage}
     */
    private function ticket(): array
    {
        $user = User::factory()->create();

        $account = TelegramSupportAccount::query()->where('name', 'support')->firstOrFail();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => self::CHAT_ID],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => random_int(1_100_000, 1_999_999),
            'direction' => 'incoming',
            'text' => 'Здравствуйте, вопрос по курсу.',
            'sent_at' => now(),
        ]);

        return [$user, $incoming];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{status: string, category: ?string}
     */
    private function callSendAuto(
        TelegramSupportMessage $incoming,
        User $user,
        string $draft,
        string $kind,
        array $meta = [],
    ): array {
        $service = app(SupportDmAutoReply::class);
        $method = new ReflectionMethod($service, 'sendAuto');

        return $method->invoke($service, $incoming, $user, null, $draft, $kind, $meta);
    }
}
