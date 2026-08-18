<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HomeworkSubmission;
use App\Services\HomeworkImagePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Сборка `combined-images.pdf` одной сдачи — вне пути запроса (H3095).
 *
 * Почему job, а не вызов в сервисе: сборка держит в памяти base64 всех
 * страниц и декод кадра внутри dompdf. На php-fpm (128M) исчерпание памяти —
 * ФАТАЛЬНАЯ ошибка PHP, которую не ловит `try/catch`, поэтому страховка
 * `rebuildQuietly()` не срабатывала и падал весь POST сдачи вместе с
 * уведомлением проверяющего (H3092, [FINDINGS §483]). На воркере лимит из
 * CLI-ini (768M на .92), и падение сборки не задевает никого, кроме себя.
 *
 * Очередь `imports` (соединение `redis-long`, supervisor-long, timeout 600):
 * это самая длинная из существующих очередей, новой инфраструктуры не нужно.
 *
 * `tries = 1` — как у всего supervisor-long. Повтор не нужен: PDF
 * необязателен, а при открытии он досбирается лениво в
 * `HomeworkController::downloadImagesPdf()`.
 *
 * Ошибки НЕ глушатся (`rebuild()`, не `rebuildQuietly()`): раз от сборки
 * больше ничего не зависит, падение полезнее видеть в Horizon, чем в логе.
 */
final class BuildHomeworkImagesPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $submissionId)
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
        $this->onQueue('imports');
    }

    /**
     * Работа могла быть удалена, пока job ждала очереди — тогда собирать нечего.
     */
    public function handle(HomeworkImagePdfService $pdf): void
    {
        $submission = HomeworkSubmission::with(['comments.files', 'user', 'lesson', 'course'])
            ->find($this->submissionId);

        if ($submission === null) {
            return;
        }

        $pdf->rebuild($submission);
    }
}
