<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\KnowledgeChunk;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\HybridRetriever;
use App\Services\Support\Faq\KnowledgeVectors;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * H5065 — домен скора в отвечающей полосе.
 *
 * Что здесь пиннится и почему это не теория. У гибридного ретривала две шкалы:
 * `score` (RRF, порядка 1/(60+rank) ≈ 0.025) и `bm25_score` (лексическая нога,
 * 0…20). Все пороги полосы — `shadow_min_score`, `shadow_min_score_by_category`,
 * `llm_replies.min_score` — выведены командой faq:score-floor В ДОМЕНЕ BM25.
 *
 * Пока читался `score`, включение плотной ноги (`FAQ_HYBRID_RETRIEVAL=true`)
 * означало, что 0.025 сравнивается с 15.7: полоса молча перестаёт отвечать на
 * КАЖДЫЙ вопрос, не падая и не логируя это как дефект. Замер 17-09-2026 на
 * живом корпусе: «будет ли сертификат» → найден верный раздел
 * (`политика-и-поддержка/сертификат`), fused 0.0246, bm25 16.6749 при пороге
 * категории F 15.7.
 *
 * Второй тест — регрессия на уровне полосы: бот обязан ОТВЕТИТЬ студенту при
 * живой плотной ноге, а не только «не упасть».
 */
class SupportFaqScoreDomainTest extends TestCase
{
    use RefreshDatabase;

    private const MATERIALS_QUESTION = 'куда загружать домашнее задание и в каком формате';

    private const MATERIALS_CHUNK = 'политика-и-поддержка/домашние-задания-и-проверка';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_auto_reply_live_faq' => true,
            'features.support_auto_reply_templates' => false,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'support.faq_rag.path' => base_path('tests/fixtures/faq_live_f_corpus.md'),
            'support.faq_rag.extra_paths' => [],
            'support.faq_rag.live_categories' => ['F'],
            'support.faq_rag.shadow_min_score' => 0.5,
            'support.faq_rag.shadow_min_score_by_category' => [],
            // Плотная нога ВКЛючена и живая: ровно то состояние, в котором
            // дефект домена проявлялся.
            'features.faq_hybrid_retrieval' => true,
            'knowledge.driver' => 'ollama',
            'knowledge.embedding_model' => 'test-model',
            'knowledge.dimensions' => 4,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'auto_reply_enabled' => true,
        ]);

        $this->seedDenseLeg();
    }

    public function test_threshold_domain_is_bm25_not_the_fused_rrf_score(): void
    {
        $hits = app(HybridRetriever::class)->retrieve(self::MATERIALS_QUESTION, 3);

        $this->assertNotSame([], $hits);
        $this->assertSame(self::MATERIALS_CHUNK, $hits[0]['chunk_id']);
        $this->assertLessThan(0.1, $hits[0]['score'], 'RRF-скор живёт у 1/k, а не на шкале BM25');
        $this->assertGreaterThan(0.5, $hits[0]['bm25_score'], 'порог полосы читается из домена BM25');

        // Единственный разрешённый способ получить скор для порога.
        $this->assertSame((float) $hits[0]['bm25_score'], HybridRetriever::bm25Score($hits[0]));
        $this->assertNotSame((float) $hits[0]['score'], HybridRetriever::bm25Score($hits[0]));
    }

    public function test_live_faq_branch_answers_the_student_with_the_dense_leg_live(): void
    {
        $this->handleQuestion(self::MATERIALS_QUESTION);

        $sent = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->first();

        $this->assertNotNull(
            $sent,
            'при живой плотной ноге живая FAQ-ветка обязана отвечать: до H5065 она читала fused-скор 0.025 против порога 0.5 и молчала на каждом вопросе',
        );
        $this->assertSame('faq_rag', $sent->meta['kind']);
        $this->assertSame(self::MATERIALS_CHUNK, $sent->meta['chunk_id']);
        $this->assertGreaterThan(
            0.5,
            (float) $sent->meta['score'],
            'в событие пишется скор ТОЙ ЖЕ шкалы, что и порог, иначе задним числом не проверить, чем он был',
        );
    }

    /**
     * Dense-нога живая: вектор запроса коллинеарен вектору раздела-ответа.
     */
    private function seedDenseLeg(): void
    {
        KnowledgeChunk::create([
            'faq_chunk_id' => self::MATERIALS_CHUNK,
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'v1',
        ]);

        $this->app->instance(
            EmbeddingProvider::class,
            new FakeEmbeddingProvider(vectors: [self::MATERIALS_QUESTION => [1.0, 0.0, 0.0, 0.0]]),
        );
    }

    private function handleQuestion(string $text, int $chatId = 9901): User
    {
        $user = User::factory()->create();

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
}
