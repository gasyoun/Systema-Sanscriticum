<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\SupportAnswerSuggestion;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeContext;
use App\Services\Support\SupportAnswerSuggester;
use App\Services\Support\SupportDmLlmReplyComposer;
use App\Services\Support\SupportLlmDraftComposer;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H6182 (Epic E026, Hermes W1): сквозная проверка PII на TG и web границах LLM.
 *
 * ПРОВЕРЯЕТ доставленный H6144 (фиксы 1-3 по вердикту H6140 на PR #3027) и
 * H6352-r3/H6918-2, НЕ дублируя реализацию: оба LLM-шва читаются как есть,
 * меняются только фикстуры и provider-mock (Http::fake — сеть не ходит).
 *
 * Два транспорта, один и тот же контракт PII на каждом:
 *  - TG  = {@see SupportDmLlmReplyComposer} (шов DM-ответов Telegram-поддержки);
 *  - web = {@see SupportAnswerSuggester} → {@see SupportLlmDraftComposer}
 *    (швом «web» считается полный конвейер веб-чата: ChatMessage →
 *    категоризация → LLM-черновик → внешний провайдер).
 *
 * Проверяемые сценарии миссии на ОБОИХ транспортах:
 *  1. форматированные телефоны ('+7 (916) 123-45-67', '8-916-123-45-67',
 *     '+79161234567' — одна личность по H6144), e-mail и имена (identity-сет
 *     И газетир/H6918) не доходят до провайдера сырыми — provider mock
 *     получает ТОЛЬКО маскированный текст (assert по payload целиком,
 *     все messages, а не только фрагменту вопроса);
 *  2. exception маскера → внешний вызов отменён (fail-closed): на TG шве —
 *     compose() = null до провайдера; на web шве текст исключён ИЗ промпта,
 *     а второй слой ({@see CuratorAi::chatWithUsage()},
 *     H6352-r3) гасит вызов целиком — Http::assertNothingSent на обоих;
 *  3. пустой ответ маскера (['', 0]) → TG: вызова нет; web: вопрос исключён
 *     из промпта, провайдер формулирует ТОЛЬКО из фактов LMS (доставленный
 *     контракт H6144 для этого шва: «чистоту доказать нельзя → текста нет»),
 *     сырой вопрос в payload отсутствует.
 *
 * Fixtures полностью синтетические (никаких ученических переписок); прод,
 * отправки и платные API исключены (Exclusions handoff'а).
 */
class H6182HermesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT = 'Черновик ответа, сформулированный ИИ по фактам.';

    protected function setUp(): void
    {
        parent::setUp();

        // Общий контур внешнего провайдера: base_url на никогда не существующий
        // домен + Http::fake в каждом тесте — реальная сеть не ходит.
        config([
            'features.support_dm_llm_drafts' => true,
            'features.support_dm_llm_drafts_local' => false,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.example/api/v1',
            'services.openrouter.model' => 'test-model',
        ]);
    }

    /**
     * TG, сценарий 1: форматированные телефоны, e-mail и имена уходят в
     * провайдер ТОЛЬКО маскированными. Телефон пользователя из identity-сета
     * написан тремя способами (H6144) — ни один не должен быть в payload.
     */
    public function test_tg_boundary_masks_formatted_phones_email_and_names_in_provider_payload(): void
    {
        $this->seedStudents();

        $payloads = [];
        Http::fake(function (Request $request) use (&$payloads) {
            $payloads[] = $request->data();

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => self::DRAFT]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            ], 200);
        });

        $result = app(SupportDmLlmReplyComposer::class)->compose(
            'Иван Петров не видит урок 3. Почта ivan.petrov@example.com, запасная unknown.person@example.net. '
            .'Тел +7 (916) 123-45-67 или 8-916-123-45-67, пишите также на @ivan_p.',
            $this->dmContext(),
        );

        $this->assertNotNull($result, 'happy path: маскированный вопрос проходит в формулировку');
        $this->assertSame(self::DRAFT, $result['draft']);
        $this->assertProviderReceivedOnlyMaskedText($payloads, [
            'Иван Петров',
            'ivan.petrov@example.com',
            'unknown.person@example.net',
            '(916) 123-45-67',
            '8-916-123-45-67',
            '123-45-67',
            '9161234567',
            '@ivan_p',
        ]);
    }

    /**
     * TG, сценарий 2: exception маскера → текст исключён из промпта →
     * compose() = null ДО всякого вызова провайдера (H6144, фикс 2:
     * fail-closed второго шва — «чистоту доказать нельзя → вызова нет»).
     */
    public function test_tg_boundary_masker_exception_cancels_provider_call(): void
    {
        $this->bindBrokenMasker();
        Http::fake();

        $result = app(SupportDmLlmReplyComposer::class)->compose(
            'Иван Петров не видит урок 3, тел +7 (916) 123-45-67',
            $this->dmContext(),
        );

        $this->assertNull($result, 'маскер бросает → формулировки нет, полоса уходит в ack/шаблон');
        Http::assertNothingSent();
    }

    /**
     * TG, сценарий 3: пустой ответ маскера → вопроса в промпте нет →
     * формулировать нечего, вызова провайдера нет.
     */
    public function test_tg_boundary_empty_masker_response_cancels_provider_call(): void
    {
        $masker = $this->createMock(SupportStudentPiiMasker::class);
        $masker->method('maskOrFail')->willReturn(['', 0]);
        $this->instance(SupportStudentPiiMasker::class, $masker);
        Http::fake();

        $result = app(SupportDmLlmReplyComposer::class)->compose(
            'Иван Петров не видит урок 3, тел +7 (916) 123-45-67',
            $this->dmContext(),
        );

        $this->assertNull($result, 'маскер вернул пустой текст → вызова LLM нет');
        Http::assertNothingSent();
    }

    /**
     * Web, сценарий 1: полный конвейер веб-чата (ChatMessage → категория D →
     * LLM-черновик). Форматированный телефон, e-mail и имя нелинкованного
     * человека (газетир H6918, а не identity-сет) — только маскированными.
     */
    public function test_web_boundary_masks_formatted_phones_email_and_names_in_provider_payload(): void
    {
        $this->enableWebPipeline();
        $user = $this->studentWithTariff();

        $secret = 'Мария Ковалёва спрашивает: сколько стоит полный курс? '
            .'Мой телефон +7 (916) 123-45-67, почта maria.kovalyova@example.com.';
        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => $secret, 'is_read' => false]);

        $payloads = [];
        Http::fake(function (Request $request) use (&$payloads) {
            $payloads[] = $request->data();

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => self::DRAFT]]],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 60],
            ], 200);
        });

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created'], 'черновик по факту LMS создан');
        $this->assertSame(self::DRAFT, SupportAnswerSuggestion::first()->draft_text);
        $this->assertProviderReceivedOnlyMaskedText($payloads, [
            'Мария',
            'Ковалёва',
            'maria.kovalyova@example.com',
            'maria.kovalyova',
            '(916) 123-45-67',
            '123-45-67',
            '9161234567',
        ]);
    }

    /**
     * Web, сценарий 2: exception маскера → композер исключает вопрос из
     * промпта (H6144), а второй слой в CuratorAi::chatWithUsage (H6352-r3:
     * маскер недоступен → чистоту промпта доказать нельзя) гасит внешний
     * вызов целиком: черновика нет, Http::assertNothingSent.
     */
    public function test_web_boundary_masker_exception_cancels_provider_call(): void
    {
        $this->enableWebPipeline();
        $this->bindBrokenMasker();
        $user = $this->studentWithTariff();

        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Сколько стоит полный курс? Меня зовут Мария Ковалёва, тел +7 (916) 123-45-67.',
            'is_read' => false,
        ]);

        Http::fake();

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created'], 'fail-closed: без доказуемо чистого промпта черновика нет');
        $this->assertDatabaseCount('support_answer_suggestions', 0);
        Http::assertNothingSent();
    }

    /**
     * Web, сценарий 3: пустой ответ маскера (['', 0]) → вопрос исключён из
     * промпта, НО доставленный контракт web-шва (H6144 по образцу
     * maskedPromptText) формулирует черновик ИЗ ОДНИХ ФАКТОВ LMS: вызов
     * провайдера есть, сырого вопроса в payload нет вообще. Частичный мок
     * держит реальным всё, кроме maskOrFail — второй слой (CuratorAi)
     * работает настоящим кодом.
     */
    public function test_web_boundary_empty_masker_response_excludes_question_text_from_provider_payload(): void
    {
        $this->enableWebPipeline();
        $masker = $this->createPartialMock(SupportStudentPiiMasker::class, ['maskOrFail']);
        $masker->method('maskOrFail')->willReturn(['', 0]);
        $this->instance(SupportStudentPiiMasker::class, $masker);
        $user = $this->studentWithTariff();

        $secret = 'Сколько стоит полный курс? Меня зовут Мария Ковалёва, тел +7 (916) 123-45-67, почта maria.kovalyova@example.com.';
        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => $secret, 'is_read' => false]);

        $payloads = [];
        Http::fake(function (Request $request) use (&$payloads) {
            $payloads[] = $request->data();

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => self::DRAFT]]],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 60],
            ], 200);
        });

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created'], 'доставленный контракт web-шва: черновик из фактов, без текста вопроса');
        $this->assertProviderReceivedOnlyMaskedText($payloads, [
            'Мария',
            'Ковалёва',
            'maria.kovalyova@example.com',
            'maria.kovalyova',
            '(916) 123-45-67',
            '123-45-67',
            '9161234567',
            'Вопрос студента',
        ], false);
        $this->assertStringContainsString('4800', $this->payloadJson($payloads), 'факты LMS в промпте остаются');
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Provider mock обязан получить ТОЛЬКО маскированный (или вовсе без
     * текста вопроса) payload: ни один секрет не встречается НИГДЕ во всём
     * теле (все messages, system+user, весь JSON); в happy path обязателен
     * хотя бы один тег-псевдоним <PII:…>, в сценарии исключённого вопроса
     * ($requireTag=false) тегов нет — текст исключён целиком.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @param  list<string>  $secrets
     */
    private function assertProviderReceivedOnlyMaskedText(array $payloads, array $secrets, bool $requireTag = true): void
    {
        $this->assertNotEmpty($payloads, 'внешний вызов обязан был состояться');
        $json = $this->payloadJson($payloads);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $json, 'сырой фрагмент «'.$secret.'» дошёл до провайдера');
        }
        if ($requireTag) {
            $this->assertStringContainsString('<PII:', $json, 'в payload нет ни одного тега-псевдонима');
        }
    }

    /**
     * Полный JSON всех отправленных тел — с UNESCAPED_UNICODE, чтобы кириллица
     * искалась как есть, а не в \u-эскейпах.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    private function payloadJson(array $payloads): string
    {
        $bodies = [];
        foreach ($payloads as $payload) {
            $bodies[] = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n--\n", $bodies);
    }

    /** Identity-сет для TG-сценариев (тот же набор, что в SupportStudentPiiMaskerTest). */
    private function seedStudents(): void
    {
        // phone/telegram_username/vk_id вне $fillable — контактные поля через forceFill.
        User::factory()->create(['name' => 'Иван Петров', 'email' => 'ivan.petrov@example.com'])
            ->forceFill(['phone' => '+79161234567', 'telegram_username' => 'ivan_p', 'vk_id' => '100500'])->save();
        User::factory()->create(['name' => 'Анна Смирнова', 'email' => 'anna.smirnova@example.com'])
            ->forceFill(['phone' => '+79031234599', 'telegram_username' => 'anna_s', 'vk_id' => '200800'])->save();
        User::factory()->create(['name' => 'gasyoun']);
    }

    /** Сломанный маскер: оба слоя (композер и CuratorAi) видят исключение. */
    private function bindBrokenMasker(): void
    {
        $masker = $this->createMock(SupportStudentPiiMasker::class);
        $masker->method('maskOrFail')->willThrowException(new \RuntimeException('identity source unreachable'));
        $masker->method('maskAll')->willThrowException(new \RuntimeException('identity source unreachable'));
        $this->instance(SupportStudentPiiMasker::class, $masker);
    }

    /** Включить web-конвейер суггестера без шаблонных перехватов (LLM-полоса). */
    private function enableWebPipeline(): void
    {
        config([
            'features.support_answer_suggester' => true,
            'features.support_template_drafts' => false,
            'features.support_auto_reply_templates' => false,
            'features.support_ai_assist' => true,
        ]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();
    }

    /** Студент с группой, курсом и активным тарифом — факты категории D есть. */
    private function studentWithTariff(): User
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Группа Йога-сутры']);
        $user->groups()->attach($group->id);

        $course = Course::factory()->create(['title' => 'Йога-сутры Патанджали']);
        $group->courses()->attach($course->id);

        Tariff::create([
            'course_id' => $course->id,
            'title' => 'Полный курс',
            'type' => 'course',
            'price' => 4800,
            'is_active' => true,
        ]);

        return $user;
    }

    private const DM_CHUNK = 'политика-и-поддержка/домашние-задания-и-проверка';

    private function dmContext(): KnowledgeContext
    {
        $parser = app(FaqCorpusParser::class);
        foreach ($parser->parseFile(base_path('tests/fixtures/faq_live_f_corpus.md')) as $candidate) {
            if ($candidate->chunkId === self::DM_CHUNK) {
                return new KnowledgeContext([['chunk' => $candidate, 'score' => 9.0, 'bm25_score' => 9.0]], 'вопрос');
            }
        }

        $this->fail('фикстура корпуса обязана содержать раздел с домашними заданиями');
    }
}
