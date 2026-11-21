<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\Knowledge\LessonAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Один запрос ИИ-помощника занятия (features.lesson_ai). Модель на узле
 * отвечает 10–90 секунд — в request-path это не держим: контроллер ставит
 * джобу и отдаёт токен, страница опрашивает {@see self::key()}.
 *
 * Состояние и ответ живут только в кэше и только {@see self::TTL} — тексты
 * студента (заметки, выделенный фрагмент, вопрос) нигде не сохраняются.
 * Ретраев нет: студент ждёт на странице и сам нажмёт «Повторить».
 */
final class LessonAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const TTL = 1800;

    public int $tries = 1;

    /** Длинная стенограмма = несколько вызовов модели подряд; супервизор режет на 600. */
    public int $timeout = 540;

    /**
     * @param  array{text?: string, notes?: string, question?: string}  $input
     */
    public function __construct(
        public readonly string $token,
        public readonly int $lessonId,
        public readonly string $task,
        public readonly array $input,
    ) {
        $this->onQueue('imports');
    }

    public static function key(string $token): string
    {
        return 'lesson_ai.job.'.$token;
    }

    public function handle(LessonAiService $service): void
    {
        $lesson = Lesson::with('course')->find($this->lessonId);
        if (! $lesson instanceof Lesson) {
            $this->finish(['status' => 'error', 'error' => 'gone']);

            return;
        }

        try {
            $result = $service->run($this->task, $lesson, $this->input);
        } catch (RuntimeException $e) {
            // Студент ничего не получил — и лимит не тратит.
            $this->refund($service);
            $this->finish(['status' => 'error', 'error' => $e->getMessage()]);

            return;
        }

        $this->finish(self::donePayload($service, $this->task, $result));
    }

    public function failed(Throwable $e): void
    {
        Log::warning('LessonAiJob failed', ['task' => $this->task, 'lesson_id' => $this->lessonId, 'error' => $e->getMessage()]);
        $this->refund(app(LessonAiService::class));
        $this->finish(['status' => 'error', 'error' => 'provider']);
    }

    /** Один возврат на токен — повторный failed() после handle() не вернёт дважды. */
    private function refund(LessonAiService $service): void
    {
        $state = Cache::get(self::key($this->token));
        if (! is_array($state) || ! empty($state['refunded'])) {
            return;
        }
        $service->refundQuota((int) $state['user_id']);
        Cache::put(self::key($this->token), $state + ['refunded' => true], self::TTL);
    }

    /**
     * @param  array{md: string, questions?: list<array{q: string, a: string, t: string}>}  $result
     * @return array<string, mixed>
     */
    public static function donePayload(LessonAiService $service, string $task, array $result): array
    {
        $payload = [
            'status' => 'done',
            'task' => $task,
            'md' => $result['md'],
            'html' => $service->render($result['md']),
        ];
        if (isset($result['questions'])) {
            $payload['questions'] = array_map(static fn (array $q): array => [
                'q' => $q['q'],
                'a_html' => $service->render($q['a'].($q['t'] !== '' ? ' ['.$q['t'].']' : '')),
            ], $result['questions']);
        }

        return $payload;
    }

    /** @param  array<string, mixed>  $payload */
    private function finish(array $payload): void
    {
        $state = Cache::get(self::key($this->token));
        if (! is_array($state)) {
            return; // токен истёк — студент давно ушёл со страницы
        }
        Cache::put(self::key($this->token), array_merge($state, $payload), self::TTL);
    }
}
