<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ComposeStudentQuestionDraft;
use App\Models\TelegramStudentQuestion;
use App\Services\Bot\CuratorAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H8527: стопочка — CuratorAi-черновик куратору (P1: хранится, не отправляется)
 * и машиночитаемый экспорт для агентной обработки MG.
 */
class StudentQuestionQueueTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuestion(array $overrides = []): TelegramStudentQuestion
    {
        return TelegramStudentQuestion::create([
            'telegram_chat_id' => '-1003994169966',
            'telegram_message_id' => 900,
            'group_id' => null,
            'author_telegram_id' => '777001',
            'author_name' => 'Студентка Анна',
            'kind' => TelegramStudentQuestion::KIND_QUESTION,
            'post_kind' => TelegramStudentQuestion::POST_KIND_UNKNOWN,
            'question_text' => 'а что такое домашнее задание?',
            'reply_to_message_id' => 55,
            'replied_post_excerpt' => '📝 Приём ДЗ открыт',
            'status' => TelegramStudentQuestion::STATUS_NEW,
            'curator_draft_state' => TelegramStudentQuestion::DRAFT_STATE_PENDING,
            'sent_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_draft_job_generates_curator_draft(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Черновик: это пост о приёме ДЗ, задание появится ответом с тегом #ДЗ.']]],
                'model' => 'deepseek/deepseek-v4-flash',
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ]),
        ]);
        config()->set('services.openrouter.api_key', 'test-key');
        $question = $this->makeQuestion();

        (new ComposeStudentQuestionDraft($question->id))->handle(app(CuratorAi::class));

        $question->refresh();
        $this->assertSame(TelegramStudentQuestion::DRAFT_STATE_GENERATED, $question->curator_draft_state);
        $this->assertStringContainsString('Черновик', (string) $question->curator_draft);
        $this->assertSame('deepseek/deepseek-v4-flash', $question->curator_draft_model);
    }

    public function test_draft_job_flag_off_marks_off(): void
    {
        config()->set('services.telegram_zapisi.student_question_ai_draft', false);
        $question = $this->makeQuestion();

        (new ComposeStudentQuestionDraft($question->id))->handle(app(CuratorAi::class));

        $question->refresh();
        $this->assertSame(TelegramStudentQuestion::DRAFT_STATE_OFF, $question->curator_draft_state);
        $this->assertNull($question->curator_draft);
    }

    public function test_draft_job_api_failure_marks_failed(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => 'boom'], 500)]);
        config()->set('services.openrouter.api_key', 'test-key');
        $question = $this->makeQuestion();

        (new ComposeStudentQuestionDraft($question->id))->handle(app(CuratorAi::class));

        $question->refresh();
        $this->assertSame(TelegramStudentQuestion::DRAFT_STATE_FAILED, $question->curator_draft_state);
        $this->assertNull($question->curator_draft);
    }

    public function test_draft_job_skips_proposal_rows(): void
    {
        Http::fake();
        config()->set('services.openrouter.api_key', 'test-key');
        $question = $this->makeQuestion([
            'kind' => TelegramStudentQuestion::KIND_PROPOSAL,
            'curator_draft_state' => TelegramStudentQuestion::DRAFT_STATE_OFF,
        ]);

        (new ComposeStudentQuestionDraft($question->id))->handle(app(CuratorAi::class));

        $question->refresh();
        $this->assertNull($question->curator_draft);
        $this->assertSame(TelegramStudentQuestion::DRAFT_STATE_OFF, $question->curator_draft_state);
    }

    public function test_export_json_outputs_machine_readable_rows(): void
    {
        $question = $this->makeQuestion();
        $this->makeQuestion([
            'telegram_message_id' => 901,
            'kind' => TelegramStudentQuestion::KIND_PROPOSAL,
            'status' => TelegramStudentQuestion::STATUS_ANSWERED,
        ]);

        $this->assertSame(0, Artisan::call('zapisi:student-questions', ['--json' => true]));
        $rows = json_decode((string) Artisan::output(), true);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($question->id, $row['id']);
        $this->assertSame('-1003994169966', $row['chat_id']);
        $this->assertSame('question', $row['kind']);
        $this->assertSame('new', $row['status']);
        $this->assertSame('777001', $row['author']['telegram_id']);
        $this->assertSame('pending', $row['draft']['state']);

        $this->assertSame(0, Artisan::call('zapisi:student-questions', ['--json' => true, '--status' => 'answered']));
        $answered = json_decode((string) Artisan::output(), true);
        $this->assertCount(1, $answered);
        $this->assertSame('proposal', $answered[0]['kind']);
    }
}
