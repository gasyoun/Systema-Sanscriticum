<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BuildLectureHtmlJob;
use App\Jobs\PreprocessLectureDraftJob;
use App\Jobs\RunLectureAiJob;
use App\Models\LectureDraft;
use App\Services\Lecture\LectureAiClient;
use App\Services\Lecture\LectureBuilderClient;
use App\Services\Lecture\LectureStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase A: длинные шаги редактора лекций (препроцесс/сборка/ИИ) вынесены в очередь
 * `lectures`. Признак «идёт обработка» живёт в meta, джобы снимают его в finally.
 */
class LectureAsyncPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lecture_builder.url' => 'http://lecture-builder.test']);
    }

    private function draft(array $attrs = []): LectureDraft
    {
        return LectureDraft::create(array_merge([
            'title' => 'Тестовая лекция',
            'status' => LectureDraft::STATUS_EDITING,
            'meta' => ['lesson_number' => 1],
        ], $attrs));
    }

    private function fakeBuilder(): void
    {
        Http::fake([
            'lecture-builder.test/preprocess' => Http::response(['ok' => true, 'data_json' => 'data.json', 'slides' => []]),
            'lecture-builder.test/render' => Http::response(['ok' => true, 'output' => 'output/lecture.html']),
            'lecture-builder.test/ai/*' => Http::response(['ok' => true, 'summary' => 'Исправлено 5 абзацев', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50], 'backup' => '20260625_ai.json']),
        ]);
    }

    // ── Модель: признак обработки ────────────────────────────────────────────

    /** @test */
    public function processing_flag_lifecycle(): void
    {
        $draft = $this->draft();
        $this->assertFalse($draft->isProcessing());

        $draft->markProcessing('ИИ: корректура текста');
        $this->assertTrue($draft->fresh()->isProcessing());
        $this->assertSame('ИИ: корректура текста', $draft->fresh()->processingLabel());
        $this->assertNotNull($draft->fresh()->processingStartedAt());

        $draft->clearProcessing();
        $this->assertFalse($draft->fresh()->isProcessing());
    }

    /** @test */
    public function clear_processing_keeps_other_meta(): void
    {
        $draft = $this->draft(['meta' => ['lesson_number' => 7, 'lecturer' => 'Иванов']]);
        $draft->markProcessing('Сборка HTML');
        $draft->clearProcessing();

        $meta = $draft->fresh()->meta;
        $this->assertSame(7, $meta['lesson_number']);
        $this->assertSame('Иванов', $meta['lecturer']);
        $this->assertArrayNotHasKey('processing', $meta);
    }

    // ── Очередь ──────────────────────────────────────────────────────────────

    /** @test */
    public function long_jobs_target_the_lectures_queue(): void
    {
        $this->assertSame('lectures', (new BuildLectureHtmlJob(1))->queue);
        $this->assertSame('lectures', (new RunLectureAiJob(1, 'correct'))->queue);
        $this->assertSame('lectures', (new PreprocessLectureDraftJob(1, 't.txt', null, 1))->queue);
    }

    // ── Препроцесс ───────────────────────────────────────────────────────────

    /** @test */
    public function preprocess_job_runs_initial_build_and_clears_processing(): void
    {
        $this->fakeBuilder();
        $draft = $this->draft(['status' => LectureDraft::STATUS_DRAFT]);
        $draft->markProcessing('Препроцесс и первая сборка');

        (new PreprocessLectureDraftJob($draft->id, 'transcript.txt', null, 1))
            ->handle(app(LectureStorage::class), app(LectureBuilderClient::class));

        $draft->refresh();
        $this->assertSame(LectureDraft::STATUS_BUILT, $draft->status); // прошёл и render
        $this->assertNotNull($draft->output_html_path);
        $this->assertFalse($draft->isProcessing(), 'processing-флаг должен сняться в finally');
    }

    // ── Сборка ───────────────────────────────────────────────────────────────

    /** @test */
    public function standalone_build_clears_processing_but_nested_does_not(): void
    {
        $this->fakeBuilder();

        $owning = $this->draft();
        $owning->markProcessing('Сборка HTML');
        (new BuildLectureHtmlJob($owning->id, ownsProcessing: true))->handle(
            app(LectureStorage::class), app(LectureBuilderClient::class)
        );
        $this->assertFalse($owning->fresh()->isProcessing());

        $nested = $this->draft();
        $nested->markProcessing('Препроцесс и первая сборка');
        (new BuildLectureHtmlJob($nested->id, ownsProcessing: false))->handle(
            app(LectureStorage::class), app(LectureBuilderClient::class)
        );
        $this->assertTrue($nested->fresh()->isProcessing(), 'вложенная сборка не должна снимать флаг владельца');
    }

    // ── ИИ ───────────────────────────────────────────────────────────────────

    /** @test */
    public function ai_job_applies_records_summary_and_clears_processing(): void
    {
        $this->fakeBuilder();
        $draft = $this->draft();
        $draft->markProcessing('ИИ: корректура текста');

        (new RunLectureAiJob($draft->id, 'correct', '', ['max_paragraphs' => 5]))->handle(
            app(LectureStorage::class), app(LectureAiClient::class)
        );

        $draft->refresh();
        $this->assertFalse($draft->isProcessing());
        $this->assertStringContainsString('Исправлено 5 абзацев', $draft->meta['last_ai']['summary']);
        $this->assertSame(LectureDraft::STATUS_BUILT, $draft->status); // ребилд прошёл
    }

    /** @test */
    public function ai_job_failure_sets_error_log_and_clears_processing(): void
    {
        Http::fake(['lecture-builder.test/ai/*' => Http::response(['ok' => false, 'error' => 'нет данных'], 422)]);
        $draft = $this->draft();
        $draft->markProcessing('ИИ: разбивка на разделы');

        try {
            (new RunLectureAiJob($draft->id, 'structure'))->handle(
                app(LectureStorage::class), app(LectureAiClient::class)
            );
            $this->fail('ожидалось исключение от упавшей AI-задачи');
        } catch (\Throwable $e) {
            // ожидаемо
        }

        $draft->refresh();
        $this->assertFalse($draft->isProcessing(), 'флаг должен сняться даже при ошибке');
        $this->assertNotNull($draft->error_log);
    }
}
