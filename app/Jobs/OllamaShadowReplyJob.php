<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SupportAiReplyEvent;
use App\Services\Bot\CuratorAi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * H3234 (issue #1633 этап 5): теневая генерация. OpenRouter уже ответил
 * студенту; этот Horizon-job прогоняет ТОТ ЖЕ промпт через локальный
 * qwen3:14b (туннель → 127.0.0.1:11434) и пишет ответ в SupportAiReplyEvent
 * (event_type=ollama_shadow) рядом с онлайн-логом — неделя живого сравнения
 * до флипа этапа 6. Узел умер → событие со status=error, студенту ничего
 * не уходит, наружу не откатываемся.
 */
class OllamaShadowReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<array{role: string, content: string}>  $messages  ровно тот промпт, что ушёл в OpenRouter
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $messages,
        public readonly string $incomingText,
    ) {}

    public function handle(CuratorAi $ai): void
    {
        $result = $ai->localChatWithUsage($this->messages);
        $content = $result['content'];

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => SupportAiReplyEvent::EVENT_OLLAMA_SHADOW,
            'meta' => [
                'scope' => 'bot_shadow',
                'user_id' => $this->userId,
                'status' => $content !== null ? 'ok' : 'error',
                'question_preview' => mb_substr($this->incomingText, 0, 240),
                'preview' => $content !== null ? mb_substr($content, 0, 240) : null,
                'model' => $result['model'],
                'usage' => $result['usage'],
            ],
        ]);

        if ($content === null) {
            Log::info('OllamaShadowReplyJob: локальный узел не ответил (status=error записан в тень)');
        }
    }
}
