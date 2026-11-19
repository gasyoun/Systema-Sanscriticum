<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\User;
use App\Services\Bot\CuratorAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 0YW: F-only citation in the cabinet bot (CuratorAi) before any LLM call.
 *
 * OFF invariant is the important half: with the flag down the bot must take the
 * old path byte-for-byte. ON: category F above the shared floor is answered from
 * faq.md with a namespace citation; D/E never reach the citation branch.
 */
class CuratorAiFaqCitationTest extends TestCase
{
    use RefreshDatabase;

    private const F_QUESTION = 'куда загружать домашнее задание и в каком формате';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'support.faq_rag.path' => base_path('tests/fixtures/faq_live_f_corpus.md'),
            'support.faq_rag.shadow_min_score_by_category' => ['F' => 0.5],
            'features.bot_faq_live_f' => true,
            'features.bot_local_generation' => false,
            'features.bot_ollama_shadow' => false,
            'services.openrouter.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'LLM answer']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);
    }

    public function test_f_question_is_answered_with_citation_and_no_provider_call(): void
    {
        $user = User::factory()->create();

        $answer = app(CuratorAi::class)->reply($user, self::F_QUESTION);

        $this->assertIsString($answer);
        $this->assertStringContainsString('Источник — наш FAQ', $answer);
        Http::assertNothingSent();
    }

    public function test_off_flag_falls_through_to_the_llm(): void
    {
        config(['features.bot_faq_live_f' => false]);
        $user = User::factory()->create();

        $answer = app(CuratorAi::class)->reply($user, self::F_QUESTION);

        $this->assertSame('LLM answer', $answer);
        Http::assertSentCount(1);
    }

    public function test_money_question_never_uses_the_f_citation(): void
    {
        $user = User::factory()->create();

        $answer = app(CuratorAi::class)->reply($user, 'сколько стоит курс и есть ли рассрочка');

        $this->assertSame('LLM answer', $answer);
        Http::assertSentCount(1);
    }
}
