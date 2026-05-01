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
 * Препроцесс черновика лекции:
 *   raw/slides.pdf + raw/transcript.txt → slides/*.jpg + data.json
 *
 * Ходит в lecture-builder. На входе — сырые файлы, уже сохранённые
 * в storage/app/lectures/{id}/raw/.
 *
 * При QUEUE_CONNECTION=sync выполняется синхронно (сейчас так настроено в проекте).
 */
final class PreprocessLectureDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly int $draftId,
        public readonly string $rawTranscriptName,
        public readonly ?string $rawPdfName,
        public readonly int $lessonNumber,
    ) {}

    public function handle(
        LectureStorage $storage,
        LectureBuilderClient $client,
    ): void {
        $draft = LectureDraft::find($this->draftId);
        if ($draft === null) {
            Log::warning('PreprocessLectureDraftJob: черновик не найден', ['id' => $this->draftId]);
            return;
        }

        $draft->forceFill([
            'status'    => LectureDraft::STATUS_PREPROCESSING,
            'error_log' => null,
        ])->save();

        try {
            $absoluteWorkingDir = $storage->absoluteWorkingDir($draft);

            $result = $client->preprocess(
                absoluteWorkingDir: $absoluteWorkingDir,
                rawTranscriptRel: 'raw/' . $this->rawTranscriptName,
                rawPdfRel: $this->rawPdfName ? 'raw/' . $this->rawPdfName : null,
                lessonNumber: $this->lessonNumber,
                meta: $draft->meta ?? [],
            );

            $draft->forceFill([
                'status'         => LectureDraft::STATUS_EDITING,
                'data_json_path' => $storage->relativePath($draft, $result['data_json'] ?? 'data.json'),
                'slides_dir'     => $storage->relativePath($draft, 'slides'),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('PreprocessLectureDraftJob failed', [
                'draft_id' => $draft->id,
                'error'    => $e->getMessage(),
            ]);
            $draft->forceFill([
                'status'    => LectureDraft::STATUS_DRAFT,
                'error_log' => $e->getMessage(),
            ])->save();
            throw $e;
        }
    }
}
