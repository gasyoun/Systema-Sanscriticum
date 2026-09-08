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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4404 (рулинг MG 08-09-2026 «LLM-черновики»): LLM-ветка автоответа в личке
 * саппорта.
 *
 * Ключевое отличие от шаблонной и FAQ-веток: от классификатора категорий
 * ветка НЕ зависит — в пробе H3380 он вернул category=null на 8 из 8 живых
 * dm_hinted, значит категорийный гейт в живом трафике не стрелял бы вовсе.
 * Набор пинит: R3-отказы (деньги/доступы/спам) ДО вызова LLM, cooldown
 * «один LLM-ответ на серию», приоритет живой FAQ-ветки, аудит-события с
 * версией промпта и моделью, тень (инвариант §5: студенту не уходит ничего,
 * пока флаги OFF).
 */
class SupportDmLlmDraftsTest extends TestCase
{
    use RefreshDatabase;

    /** Не классифицируется ни одной категорией suggester'а — ровно трафик, ради которого ветка строится. */
    private const UNCLASSIFIED_QUESTION = 'подскажите, до скольки открывается здание школы перед занятиями';

    private const LLM_DRAFT = 'Здравствуйте. Школа открывается за полчаса до начала занятия, можно подождать в холле.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_llm_drafts' => true,
            'features.support_dm_auto_reply_live_faq' => false,
            'features.support_auto_reply_templates' => false,
            'features.support_auto_ack' => false,
            'features.support_dm_auto_reply_shadow' => true,
            'features.support_dm_llm_drafts_live' => false,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.model' => 'deepseek/deepseek-chat',
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            // Крошечный неподвижный корпус: живой faq.md меняется при каждом
            // экспорте, и абсолютные скоры на нём плыли бы.
            'support.faq_rag.path' => base_path('tests/fixtures/faq_live_f_corpus.md'),
            'support.faq_rag.extra_paths' => [],
            'support.faq_rag.shadow_min_score' => 0.5,
            'support.faq_rag.shadow_min_score_by_category' => [],
            'support.llm_replies.min_score' => 0.5,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'openrouter.ai/*' => Http::response([
                'model' => 'deepseek/deepseek-chat',
                'choices' => [['message' => ['content' => self::LLM_DRAFT]]],
                'usage' => ['prompt_tokens' => 210, 'completion_tokens' => 55],
            ], 200),
        ]);

        TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'auto_reply_enabled' => true,
        ]);
    }

    public function test_unclassified_question_gets_an_llm_reply_that_templates_and_faq_let_pass(): void
    {
        config(['features.support_dm_llm_drafts_live' => true]);

        $user = $this->handleQuestion(self::UNCLASSIFIED_QUESTION);

        $sent = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertNotNull($sent, 'ветка обязана отвечать там, где классификатор вернул null');
        $this->assertSame(SupportDmAutoReply::KIND_LLM_DRAFT, $sent->meta['kind']);
        $this->assertNull($sent->meta['category'], 'ветка от категории не зависит');
        $this->assertSame(SupportDmAutoReply::LLM_PROMPT_VERSION, $sent->meta['prompt_version']);
        $this->assertSame('deepseek/deepseek-chat', $sent->meta['model']);
        $this->assertSame(55, $sent->meta['usage']['completion_tokens']);
        $this->assertNotEmpty($sent->meta['faq_chunk_ids'], 'ответ сформулирован по живым фрагментам — chunk_ids в аудите');

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertSame(self::LLM_DRAFT, $outgoing->text);

        Http::assertSent(fn ($request): bool => str_contains($this->userPromptOf($request), self::UNCLASSIFIED_QUESTION));
    }

    /** Инвариант §5: пока живой флаг OFF, тень пишет «отправил бы» и студент молчит. */
    public function test_shadow_mode_records_the_would_send_and_sends_nothing(): void
    {
        $user = $this->handleQuestion(self::UNCLASSIFIED_QUESTION);

        $shadow = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND)
            ->first();

        $this->assertNotNull($shadow, ' formulated draft обязан попасть в тень');
        $this->assertSame(self::LLM_DRAFT, $shadow->meta['draft']);
        $this->assertSame(SupportDmAutoReply::LLM_PROMPT_VERSION, $shadow->meta['prompt_version']);
        $this->assertSame('deepseek/deepseek-chat', $shadow->meta['model']);
        $this->assertArrayNotHasKey('question', $shadow->meta, 'текст студента в событие не кладём');

        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'инвариант §5: в тени студенту не уходит ничего',
        );
        $this->assertSame(0, $this->sentCount());

        // Отказ-события быть не должно: LLM сработал, вопрос — только в флаге.
        $this->assertSame(0, $this->refusedCount());
    }

    /** Рулинг R3: деньги гонятся в отказ ДО всякого вызова LLM, при любом конфиге. */
    public function test_money_question_refuses_without_calling_the_llm(): void
    {
        config(['features.support_dm_llm_drafts_live' => true]);

        $this->handleQuestion('сколько стоит доплата за второй блок и можно ли по частям');

        $this->assertSame(0, $this->sentCount(), 'деньги студенту не отправляем');
        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND)->count());
        $this->assertSame(1, $this->refusedCount('money'));
        $this->assertSame(
            0,
            count(Http::recorded(fn ($request): bool => str_contains($request->url(), 'openrouter.ai'))),
            'R3: деньги — refuse-and-escalate ДО вызова LLM',
        );
    }

    /** Рулинг R3: доступы — то же самое. */
    public function test_access_question_refuses_without_calling_the_llm(): void
    {
        config(['features.support_dm_llm_drafts_live' => true]);

        $this->handleQuestion('не могу войти в личный кабинет, пишет что пароль неверный');

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(1, $this->refusedCount('access'));
        $this->assertSame(
            0,
            count(Http::recorded(fn ($request): bool => str_contains($request->url(), 'openrouter.ai'))),
            'R3: доступы — refuse-and-escalate ДО вызова LLM',
        );
    }

    /** Спам/реклама — тоже refuse, LLM не вызывается. */
    public function test_spam_question_refuses(): void
    {
        config(['features.support_dm_llm_drafts_live' => true]);

        $this->handleQuestion('здравствуйте, предлагаем продвижение вашего телеграм канала');

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(1, $this->refusedCount('spam'));
        $this->assertSame(0, count(Http::recorded(fn ($request): bool => str_contains($request->url(), 'openrouter.ai'))));
    }

    /** Живая FAQ-ветка старше: её уверенный ответ исключает формулировку модели. */
    public function test_confident_faq_hit_outranks_the_llm_branch(): void
    {
        config([
            'features.support_dm_auto_reply_live_faq' => true,
            'support.faq_rag.live_categories' => ['F'],
            'support.faq_rag.shadow_min_score_by_category' => ['F' => 0.0],
        ]);

        $this->handleQuestion('куда загружать домашнее задание и в каком формате');

        $sent = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertNotNull($sent);
        $this->assertSame('faq_rag', $sent->meta['kind'], 'уверенный FAQ отвечает сам, LLM не нужен');
        $this->assertSame(0, count(Http::recorded(fn ($request): bool => str_contains($request->url(), 'openrouter.ai'))), 'FAQ-ветка не вызывает LLM');
    }

    /** Ниже порога retrieval'а формулировать не из чего — отказ, LLM не зовётся. */
    public function test_score_below_the_floor_refuses(): void
    {
        config(['support.llm_replies.min_score' => 1000.0]);

        $this->handleQuestion(self::UNCLASSIFIED_QUESTION);

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(1, $this->refusedCount('below_score_floor'));
        $this->assertSame(0, count(Http::recorded(fn ($request): bool => str_contains($request->url(), 'openrouter.ai'))));
    }

    /** Рулинг W3: не чаще одного LLM-ответа на серию — второй в cooldown-окно молчит. */
    public function test_only_one_llm_reply_per_series(): void
    {
        config(['features.support_dm_llm_drafts_live' => true]);

        $user = $this->handleQuestion(self::UNCLASSIFIED_QUESTION, 9801);
        $second = $this->handleQuestion('и ещё: можно ли приходить со своей тетрадью', 9801, existingUser: $user);

        $this->assertSame(1, $this->sentCount(), 'один LLM-ответ на серию сообщений');
        $this->assertSame(1, $this->refusedCount('cooldown_or_account'), 'второй гасится cooldown-гейтом');
    }

    /** Провал LLM (ключ/провайдер) — не «отказ по политике»: конвейер уходит в ack/hint. */
    public function test_llm_failure_falls_through_without_a_refusal_event(): void
    {
        config(['features.support_dm_llm_drafts_live' => true]);
        config(['services.openrouter.api_key' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->handleQuestion(self::UNCLASSIFIED_QUESTION);

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, $this->refusedCount());
        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count(),
            'провал формулировки — прежний конвейер: подсказка куратору',
        );
    }

    /** Флаг ветки OFF — прежнее поведение, ни тени, ни отказа. */
    public function test_flag_off_keeps_the_legacy_pipeline(): void
    {
        config(['features.support_dm_llm_drafts' => false]);

        $this->handleQuestion(self::UNCLASSIFIED_QUESTION);

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, $this->refusedCount());
        $this->assertSame(
            0,
            SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND)->count(),
        );
        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count(),
        );
    }

    /** RU smoke-корпус: ≥10 реальных анонимизированных вопросов реплеются в тени. */
    public function test_ru_smoke_corpus_replays_through_the_lane_in_shadow(): void
    {
        $corpus = [
            'подскажите, до скольки открывается здание школы перед занятиями',
            'можно ли приходить на занятие со своим словарём',
            'где найти раздаточные материалы к вчерашнему занятию',
            'будет ли запись занятия, если я пропущу по болезни',
            'когда выдаются грамоты по окончании курса',
            'какой учебник нужен для курса с нуля',
            'нужно ли заранее готовиться к аттестационному заданию',
            'можно ли занимать в библиотеке во время перерыва',
            'как правильно оформить конспект для проверки',
            'где посмотреть список литературы к следующему модулю',
            'какой рюкзак удобнее носить на занятия',
            'что брать с собой на первое занятие',
        ];

        foreach ($corpus as $i => $question) {
            $this->handleQuestion($question, 9900 + $i);
        }

        $shadowCount = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND)
            ->count();
        $refusedCount = $this->refusedCount();

        $this->assertSame(
            count($corpus),
            $shadowCount + $refusedCount,
            "каждый вопрос корпуса обязан дойти до формулировки-в-тень или до документированного отказа (shadow={$shadowCount}, refused={$refusedCount})",
        );
        $this->assertGreaterThanOrEqual(
            10,
            $shadowCount,
            'минимум 10 из 12 вопросов корпуса дожны дойти до формулировки — иначе ветка мертва',
        );
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'инвариант §5: тень не пишет студенту',
        );
    }

    private function sentCount(): int
    {
        return SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->count();
    }

    private function refusedCount(?string $reason = null): int
    {
        $query = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_LLM_REFUSED);

        return $reason === null
            ? $query->count()
            : $query->get()->filter(fn ($event): bool => $event->meta['reason'] === $reason)->count();
    }

    private function handleQuestion(
        string $text,
        int $chatId = 9801,
        ?User $existingUser = null,
    ): User {
        $user = $existingUser ?? User::factory()->create();

        $account = TelegramSupportAccount::query()->where('name', 'support')->firstOrFail();

        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);

        app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        return $user;
    }

    private function userPromptOf(Request $request): string
    {
        $body = json_decode($request->body(), true, JSON_THROW_ON_ERROR);

        return (string) collect($body['messages'] ?? [])
            ->firstWhere('role', 'user')['content'] ?? '';
    }
}
