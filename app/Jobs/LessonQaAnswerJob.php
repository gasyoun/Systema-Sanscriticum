<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Bot\LessonQaService;
use App\Services\Bot\TelegramFormatter;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Этап 4 — доигрывает вопрос по уроку, когда домашний узел был недоступен.
 *
 * Такое случается штатно: узел Ивана — рабочая станция, 16-09-2026 она роняла
 * Ollama дважды за день. Внешнего фолбэка нет по рулингу #1633, поэтому
 * единственный честный путь — не потерять вопрос, а ответить позже.
 *
 * Ретраи живут внутри джобы (как у KnowledgeEmbedChunksJob): обрыв туннеля —
 * штатная ситуация, а не дефект данных.
 */
final class LessonQaAnswerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        public readonly int $userId,
        public readonly string $question,
        public readonly string $chatId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(LessonQaService $qa): void
    {
        $user = User::find($this->userId);
        if (! $user instanceof User) {
            return;
        }

        $result = $qa->answer($user, $this->question);

        if ($result['status'] === LessonQaService::STATUS_QUEUED) {
            // Узел всё ещё молчит — отдаём джобу планировщику очереди на
            // следующую попытку, а не «отвечаем» пустотой.
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        if ($result['status'] !== LessonQaService::STATUS_ANSWERED || $result['text'] === null) {
            return;
        }

        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'bot',
            'text' => $result['text'],
            'is_read' => true,
            'source' => 'telegram_bot',
        ]);

        $this->send($this->chatId, $result['text']);
    }

    private function send(string $chatId, string $text): void
    {
        $token = (string) (config('services.telegram.student_bot_token') ?: config('services.telegram.bot_token'));
        if ($token === '') {
            Log::warning('LessonQaAnswerJob: токен бота не настроен, ответ не отправлен', ['chat_id' => $chatId]);

            return;
        }

        if (! TelegramSendGuard::claim($chatId, $text)) {
            return;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => TelegramFormatter::toHtml($text),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful()) {
                // Телеграм ОТВЕТИЛ отказом — клейм отпускаем, повтор законен.
                TelegramSendGuard::release($chatId, $text);
                Log::warning('LessonQaAnswerJob: Telegram отклонил отправку', [
                    'status' => $response->status(),
                    'chat_id' => $chatId,
                ]);
            }
        } catch (\Throwable $e) {
            // Ответа не было вовсе: клейм ДЕРЖИМ (контракт TelegramSendGuard) —
            // сообщение могло уйти, и повтор продублировал бы его студенту.
            Log::warning('LessonQaAnswerJob: отправка не удалась: '.$e->getMessage(), ['chat_id' => $chatId]);
        }
    }
}
