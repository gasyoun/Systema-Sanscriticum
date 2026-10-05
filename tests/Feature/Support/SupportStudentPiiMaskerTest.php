<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeContext;
use App\Services\Support\SupportDmLlmReplyComposer;
use App\Services\Support\SupportLlmDraftComposer;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H6090 / ростер v2, гейт Q5: pii-маскинг текста студента до LLM-вызова
 * в suggester-шве. Контракт — tools/school_pii_guard.py (Uprava, 05-10-2026):
 * standalone-токены, allow-лист, структурные фигуры, fail-closed.
 */
class SupportStudentPiiMaskerTest extends TestCase
{
    use RefreshDatabase;

    private function seedStudents(): void
    {
        // phone/telegram_username/vk_id вне $fillable — контактные поля через forceFill.
        User::factory()->create(['name' => 'Иван Петров', 'email' => 'ivan.petrov@example.com'])
            ->forceFill(['phone' => '+79161234567', 'telegram_username' => 'ivan_p', 'vk_id' => '100500'])->save();
        User::factory()->create(['name' => 'Анна Смирнова', 'email' => 'anna.smirnova@example.com'])
            ->forceFill(['phone' => '+79031234599', 'telegram_username' => 'anna_s', 'vk_id' => '200800'])->save();
        User::factory()->create(['name' => 'gasyoun']);
    }

    public function test_masks_standalone_identities_and_structural_shapes(): void
    {
        $this->seedStudents();
        $masker = new SupportStudentPiiMasker;

        $text = 'Студент Иван Петров (ivan.petrov@example.com, +79161234567, @ivan_p, vk 100500) '
            .'просит перенести вводное. Письмо дублируйте на unknown.person@example.net, тел 89031234599.';
        [$masked, $n] = $masker->maskOrFail($text);

        foreach (['Иван Петров', 'ivan.petrov@example.com', '+79161234567', '@ivan_p', '100500'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked, $secret);
        }
        // структурные фигуры маскируются, даже если значения нет в users
        $this->assertStringNotContainsString('unknown.person@example.net', $masked);
        $this->assertStringNotContainsString('89031234599', $masked);
        $this->assertGreaterThanOrEqual(6, $n);
        $this->assertStringContainsString('<PII:', $masked);
    }

    public function test_collision_words_survive_and_allow_list_bare_handle_passes(): void
    {
        User::factory()->create(['name' => 'Подписчик']);
        $masker = new SupportStudentPiiMasker;

        // «Подписчики» — не standalone «Подписчик»; голый gasyoun — allow-лист;
        // email-shaped gasyoun@example.com маскируется (структурная фигура).
        [$masked, $n] = $masker->maskOrFail('Заголовок «Подписчики» обновлён, пишет gasyoun с gasyoun@example.com.');

        $this->assertStringContainsString('Подписчики', $masked);
        $this->assertStringContainsString(' gasyoun ', $masked);
        $this->assertStringNotContainsString('gasyoun@example.com', $masked);
        $this->assertSame(1, $n);
    }

    public function test_round_trip_is_clean(): void
    {
        $this->seedStudents();
        $masker = new SupportStudentPiiMasker;

        $text = 'Анна Смирнова, anna.smirnova@example.com, тел +79031234599 — где мой доступ к урокам?';
        [$masked] = $masker->maskOrFail($text);

        $this->assertFalse($masker->containsIdentity($masked), $masked);
    }

    public function test_prompt_text_is_masked_and_fails_closed(): void
    {
        $this->seedStudents();
        config(['features.support_ai_assist' => true]);

        $composer = new SupportLlmDraftComposer(
            $this->createMock(CuratorAi::class),
            new SupportStudentPiiMasker,
        );
        $method = new \ReflectionMethod(SupportLlmDraftComposer::class, 'maskedPromptText');

        [$promptText, $n] = $method->invoke(
            $composer,
            'Иван Петров не видит урок 3, почта для связи ivan.petrov@example.com',
            'chat_message',
        );

        $this->assertGreaterThan(0, $n);
        $this->assertStringNotContainsString('Иван Петров', $promptText);
        $this->assertStringNotContainsString('ivan.petrov@example.com', $promptText);
        $this->assertFalse((new SupportStudentPiiMasker)->containsIdentity($promptText));
    }

    /**
     * H6144, фикс 3 (P2 из вердикта H6140): regression-покрытие fail-closed
     * ветки. Проба H6140: маскер бросает → maskedPromptText возвращает ['', 0]
     * и текст исключается из промпта — раньше ветка покрывалась только именем
     * теста, без брошенного исключения.
     */
    public function test_masker_failure_fails_closed_and_excludes_question_text(): void
    {
        $masker = $this->createMock(SupportStudentPiiMasker::class);
        $masker->method('maskOrFail')->willThrowException(new \RuntimeException('identity source unreachable'));

        $composer = new SupportLlmDraftComposer(
            $this->createMock(CuratorAi::class),
            $masker,
        );
        $method = new \ReflectionMethod(SupportLlmDraftComposer::class, 'maskedPromptText');

        [$promptText, $n] = $method->invoke(
            $composer,
            'Иван Петров, тел +79161234567: где мой доступ к урокам?',
            'chat_message',
        );

        $this->assertSame('', $promptText, 'чистоту доказать нельзя → текста в промпте нет вообще');
        $this->assertSame(0, $n);
    }

    /**
     * H6144, фикс 1 (P2 из вердикта H6140): форматированные телефоны
     * ('+7 (916) 123-45-67') раньше проходили ОБА слоя и уходили в LLM сырыми,
     * а round-trip считал текст чистым.
     */
    public function test_formatted_phone_is_masked_in_both_layers_and_round_trip_stays_clean(): void
    {
        $this->seedStudents();
        $masker = new SupportStudentPiiMasker;

        // первый номер — личность из сета (user.phone), второй — вне сета,
        // но всё равно phone-shaped: под маску попадают оба слоя.
        [$masked, $n] = $masker->maskOrFail('Мой номер +7 (916) 123-45-67, перезвоните. Запасной 8(903)123-45-99.');

        $this->assertStringNotContainsString('(916)', $masked, $masked);
        $this->assertStringNotContainsString('123-45-67', $masked, $masked);
        $this->assertStringNotContainsString('123-45-99', $masked, $masked);
        $this->assertGreaterThanOrEqual(2, $n);
        $this->assertFalse($masker->containsIdentity($masked), 'round-trip обязан видеть замаскированный текст чистым');
    }

    /** H6144, фикс 1: три написания одного номера — ОДИН тег-псевдоним. */
    public function test_formatted_phone_variants_map_to_one_identity_tag(): void
    {
        $this->seedStudents();
        $masker = new SupportStudentPiiMasker;

        [$masked, $n] = $masker->maskOrFail('Телефоны: +79161234567 или +7 (916) 123-45-67, и даже 8-916-123-45-67.');

        $this->assertSame(3, $n, $masked);
        $this->assertSame(3, substr_count($masked, '<PII:phone:1>'), 'все написания — один и тот же тег: '.$masked);
        $this->assertStringNotContainsString('<PII:phone:2>', $masked);
        $this->assertFalse($masker->containsIdentity($masked), $masked);
    }

    /** H6144, фикс 2 (P2 из вердикта H6140): второй шов — вопрос в промпте DM-композера только замаскированным. */
    public function test_dm_composer_masks_question_text_before_llm_call(): void
    {
        $this->seedStudents();
        config([
            'features.support_dm_llm_drafts' => true,
            'features.support_dm_llm_drafts_local' => false,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.example/api/v1',
            'services.openrouter.model' => 'test-model',
        ]);

        $captured = null;
        Http::fake(function (Request $request) use (&$captured) {
            $captured = $request->data()['messages'][1]['content'] ?? null;

            return Http::response(['model' => 'test-model', 'choices' => [['message' => ['content' => 'ответ']]]], 200);
        });

        $result = app(SupportDmLlmReplyComposer::class)->compose(
            'Иван Петров не видит урок 3, почта ivan.petrov@example.com, тел +7 (916) 123-45-67',
            $this->dmContext(),
        );

        $this->assertNotNull($result);
        $this->assertNotNull($captured, 'внешний вызов обязан был состояться');
        $this->assertStringNotContainsString('Иван Петров', $captured);
        $this->assertStringNotContainsString('ivan.petrov@example.com', $captured);
        $this->assertStringNotContainsString('123-45-67', $captured);
        $this->assertStringContainsString('<PII:', $captured);
    }

    /** H6144, фикс 2: fail-closed второго шва — маскер бросает → вызова LLM нет, compose() = null. */
    public function test_dm_composer_fails_closed_when_masker_throws(): void
    {
        config(['features.support_dm_llm_drafts' => true]);

        $masker = $this->createMock(SupportStudentPiiMasker::class);
        $masker->method('maskOrFail')->willThrowException(new \RuntimeException('identity source unreachable'));
        $this->instance(SupportStudentPiiMasker::class, $masker);

        Http::fake();

        $result = app(SupportDmLlmReplyComposer::class)->compose(
            'Иван Петров: где мой доступ?',
            $this->dmContext(),
        );

        $this->assertNull($result, 'чистоту не доказать → формулировки нет, полоса уходит в ack/шаблон');
        Http::assertNothingSent();
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
