<?php

declare(strict_types=1);

namespace Tests\Feature\LectureClips;

use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Outbound "clip this lecture" webhook (H1452 W4).
 * Http::fake — never hits a real n8n / VK endpoint.
 */
class DispatchLectureClipExtractionJobTest extends TestCase
{
    use RefreshDatabase;

    private function writeTranscript(string $path): void
    {
        Storage::disk('public')->put($path, json_encode([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => [
                            ['word' => 'Привет', 'punctuated_word' => 'Привет.', 'start' => 0.0, 'end' => 1.0],
                            ['word' => 'Мир', 'punctuated_word' => 'Мир.', 'start' => 50.0, 'end' => 51.0],
                            ['word' => 'Конец', 'punctuated_word' => 'Конец.', 'start' => 100.0, 'end' => 101.0],
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function lesson(array $overrides = []): Lesson
    {
        return Lesson::factory()->for(Course::factory())->create($overrides);
    }

    public function test_prod_inert_when_flag_off_no_http(): void
    {
        config(['features.clip_marketing' => false]);
        Http::fake();

        $lesson = $this->lesson(['is_published' => true]);
        (new DispatchLectureClipExtractionJob($lesson->id))->handle();

        Http::assertNothingSent();
    }

    public function test_dispatches_spans_to_n8n_when_flag_on(): void
    {
        Storage::fake('public');
        $path = 'transcripts/h1452-test.json';
        $this->writeTranscript($path);

        config([
            'features.clip_marketing' => true,
            'services.n8n.clip_extract_webhook' => 'https://n8n.example/webhook/clip-extract',
            'services.n8n.clip_extract_secret' => 'out-secret',
        ]);
        Http::fake(['n8n.example/*' => Http::response(['ok' => true])]);

        $lesson = $this->lesson([
            'is_published' => true,
            'transcript_file' => $path,
            'title' => 'Лекция H1452',
            'topic' => 'Санскрит',
            'video_url' => 'https://example.com/video.mp4',
        ]);

        (new DispatchLectureClipExtractionJob($lesson->id))->handle();

        Http::assertSent(function ($request) use ($lesson) {
            return $request->url() === 'https://n8n.example/webhook/clip-extract'
                && $request->hasHeader('X-Webhook-Secret', 'out-secret')
                && $request['action'] === 'clip_lecture'
                && $request['lesson_id'] === $lesson->id
                && is_array($request['spans'])
                && count($request['spans']) >= 1
                && isset($request['callback_url']);
        });
    }

    public function test_skips_unpublished_lesson(): void
    {
        config([
            'features.clip_marketing' => true,
            'services.n8n.clip_extract_webhook' => 'https://n8n.example/webhook/clip-extract',
        ]);
        Http::fake();

        $lesson = $this->lesson(['is_published' => false]);
        (new DispatchLectureClipExtractionJob($lesson->id))->handle();

        Http::assertNothingSent();
    }

    public function test_skips_when_webhook_not_configured(): void
    {
        Storage::fake('public');
        $path = 'transcripts/h1452-empty-hook.json';
        $this->writeTranscript($path);

        config([
            'features.clip_marketing' => true,
            'services.n8n.clip_extract_webhook' => null,
        ]);
        Http::fake();

        $lesson = $this->lesson([
            'is_published' => true,
            'transcript_file' => $path,
        ]);
        (new DispatchLectureClipExtractionJob($lesson->id))->handle();

        Http::assertNothingSent();
    }
}
