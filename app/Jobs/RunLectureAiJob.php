<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LectureDraft;
use App\Services\Lecture\LectureAiClient;
use App\Services\Lecture\LectureStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Прогон одной из 4 AI-задач (structure / correct / place_slides / timecodes)
 * через lecture-builder с последующей пересборкой HTML.
 *
 * Раньше выполнялось синхронно прямо в HTTP-запросе админки (dispatchSync +
 * прямой вызов клиента) — «полный прогон ~103 абзацев = долго и дорого» блокировал
 * запрос и мог упасть по таймауту. Phase A: вынесено в очередь `lectures`.
 *
 * Итог прогона (summary/usage/backup) кладём в meta.last_ai — async-экшен не может
 * вернуть его в нотификацию, редактор видит результат в баннере после обновления.
 */
final class RunLectureAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TASKS = ['structure', 'correct', 'place_slides', 'timecodes'];

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  array{max_paragraphs?: int}  $extra
     */
    public function __construct(
        public readonly int $draftId,
        public readonly string $task,
        public readonly string $hint = '',
        public readonly array $extra = [],
    ) {
        // Очередь длинных лекционных задач (отдельный Horizon-супервизор).
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-lectures');
        }
        $this->onQueue('lectures');
    }

    public function handle(LectureStorage $storage, LectureAiClient $client): void
    {
        $draft = LectureDraft::find($this->draftId);
        if ($draft === null) {
            Log::warning('RunLectureAiJob: черновик не найден', ['id' => $this->draftId]);

            return;
        }

        $draft->forceFill(['error_log' => null])->save();

        try {
            $absoluteWorkingDir = $storage->absoluteWorkingDir($draft);

            $result = match ($this->task) {
                'structure' => $client->structure($absoluteWorkingDir, $this->hint, apply: true),
                'correct' => $client->correct(
                    $absoluteWorkingDir,
                    $this->hint,
                    apply: true,
                    maxParagraphs: (int) ($this->extra['max_paragraphs'] ?? 0),
                ),
                'place_slides' => $client->placeSlides($absoluteWorkingDir, $this->hint, apply: true),
                'timecodes' => $client->verifyTimecodes($absoluteWorkingDir, $this->hint, apply: true),
                default => throw new \InvalidArgumentException("Неизвестная AI-задача: {$this->task}"),
            };

            // После применения правок к data.json — пересобираем HTML (inline на воркере;
            // процессинг-флаг держит эта джоба, поэтому ownsProcessing не передаём).
            BuildLectureHtmlJob::dispatchSync($draft->id);

            $draft->recordAiResult($this->formatSummary($result));
        } catch (\Throwable $e) {
            Log::error('RunLectureAiJob failed', [
                'draft_id' => $draft->id,
                'task' => $this->task,
                'error' => $e->getMessage(),
            ]);
            $draft->forceFill(['error_log' => $e->getMessage()])->save();
            throw $e;
        } finally {
            $draft->clearProcessing();
        }
    }

    private function formatSummary(array $result): string
    {
        $body = $result['summary'] ?? 'Готово';
        if (! empty($result['usage'])) {
            $u = $result['usage'];
            $body .= sprintf(' · токены: in=%d, out=%d', $u['input_tokens'] ?? 0, $u['output_tokens'] ?? 0);
        }
        if (! empty($result['backup'])) {
            $body .= ' · бэкап: '.$result['backup'];
        }

        return $body;
    }
}
