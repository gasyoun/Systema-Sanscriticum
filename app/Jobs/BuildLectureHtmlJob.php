<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LectureDraft;
use App\Services\Lecture\LectureBuilderClient;
use App\Services\Lecture\LectureStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Финальная сборка HTML черновика через lecture-builder.
 *
 * Источник: storage/app/lectures/{id}/data.json
 * Результат: storage/app/lectures/{id}/output/lecture.html
 *
 * После успешной сборки статус → built; после ручной публикации → published.
 */
final class BuildLectureHtmlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly int $draftId) {}

    public function handle(
        LectureStorage $storage,
        LectureBuilderClient $client,
    ): void {
        $draft = LectureDraft::find($this->draftId);
        if ($draft === null) {
            Log::warning('BuildLectureHtmlJob: черновик не найден', ['id' => $this->draftId]);
            return;
        }

        $previousStatus = $draft->status;
        $draft->forceFill(['error_log' => null])->save();

        try {
            $absoluteWorkingDir = $storage->absoluteWorkingDir($draft);
            $result = $client->render(absoluteWorkingDir: $absoluteWorkingDir);

            $draft->forceFill([
                'status'           => LectureDraft::STATUS_BUILT,
                'output_html_path' => $storage->relativePath($draft, $result['output'] ?? 'output/lecture.html'),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('BuildLectureHtmlJob failed', [
                'draft_id' => $draft->id,
                'error'    => $e->getMessage(),
            ]);
            $draft->forceFill([
                'status'    => $previousStatus,
                'error_log' => $e->getMessage(),
            ])->save();
            throw $e;
        }
    }
}
