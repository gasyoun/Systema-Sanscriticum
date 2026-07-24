<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\Content\SpanRanker;
use App\Services\Lecture\ClipSpanPlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * «Нарежь эту лекцию» (H1452, Wave 4): шлёт лекцию + AI-таймкод-спаны
 * (ClipSpanPlanner, границы НЕ пересчитываются) в n8n-вебхук. Сам ffmpeg и
 * загрузка в VK Video/Clips выполняются в n8n, вне Laravel — эта джоба
 * только оркестрирует запрос; результат приходит обратно через
 * POST /api/webhooks/lecture-clip-callback (LectureClipCallbackWebhookController).
 *
 * Прод-инертна, пока флаг features.clip_marketing выключен — ранний возврат,
 * никакой реальный VK-пост никогда не инициируется из теста/CI.
 *
 * H1547 (Wave 1): spans теперь top-N (SpanRanker, default 5), не весь пак
 * до 12 — payload-форма к n8n не изменилась, изменился только объём.
 */
final class DispatchLectureClipExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $lessonId) {}

    public function handle(): void
    {
        if (! config('features.clip_marketing')) {
            Log::info('DispatchLectureClipExtractionJob: clip_marketing выключен — пропуск.', [
                'lesson_id' => $this->lessonId,
            ]);

            return;
        }

        $lesson = Lesson::find($this->lessonId);
        if ($lesson === null || ! $lesson->is_published) {
            Log::warning('DispatchLectureClipExtractionJob: лекция не найдена или не опубликована.', [
                'lesson_id' => $this->lessonId,
            ]);

            return;
        }

        $spans = SpanRanker::rank(ClipSpanPlanner::planSpans($lesson));
        if ($spans === []) {
            Log::warning('DispatchLectureClipExtractionJob: нет AI-таймкодов для нарезки.', [
                'lesson_id' => $this->lessonId,
            ]);

            return;
        }

        $webhook = config('services.n8n.clip_extract_webhook');
        if (empty($webhook)) {
            Log::warning('DispatchLectureClipExtractionJob: N8N_CLIP_EXTRACT_WEBHOOK не задан — пропуск.', [
                'lesson_id' => $this->lessonId,
            ]);

            return;
        }

        $response = Http::withHeaders([
            'X-Webhook-Secret' => (string) config('services.n8n.clip_extract_secret'),
        ])->timeout(15)->post($webhook, [
            'action' => 'clip_lecture',
            'lesson_id' => $lesson->id,
            'lesson_title' => $lesson->title,
            'video_url' => $lesson->video_url,
            'youtube_url' => $lesson->youtube_url,
            'rutube_url' => $lesson->rutube_url,
            'spans' => $spans,
            'callback_url' => route('webhook.lecture-clip-callback'),
        ]);

        if (! $response->successful()) {
            Log::error('DispatchLectureClipExtractionJob: n8n вернул '.$response->status(), [
                'lesson_id' => $this->lessonId,
                'body' => $response->body(),
            ]);
        }
    }
}
