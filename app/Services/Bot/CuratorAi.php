<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Единый «мозг» ИИ-куратора для обоих ботов (TG/VK). Инкапсулирует провайдера
 * (DeepSeek через OpenRouter, OpenAI-совместимый API). Контроллеры лишь
 * сохраняют входящее сообщение и шлют ответ в свой канал, а думает — этот класс.
 */
class CuratorAi
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /** Сколько последних сообщений диалога класть в контекст. */
    private const HISTORY_LIMIT = 15;

    public function __construct(private BotKnowledgeBase $knowledgeBase) {}

    /**
     * Сгенерировать ответ студенту. Подразумевается, что входящее сообщение
     * УЖЕ сохранено в ChatMessage вызывающим контроллером (оно попадёт в
     * историю последним). Возвращает текст ответа или null при ошибке/отказе.
     */
    public function reply(User $user, string $incomingText): ?string
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            Log::error('CuratorAi: OPENROUTER_API_KEY не задан');

            return null;
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->knowledgeBase->systemPrompt($incomingText)]],
            $this->history($user),
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
                // Рекомендованные OpenRouter заголовки атрибуции (ASCII).
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => 'Academy of Sanskrit (ORS) bot',
            ])->timeout(45)->post(self::ENDPOINT, [
                'model' => config('services.openrouter.model', 'deepseek/deepseek-chat'),
                'messages' => $messages,
                'temperature' => 0.3,
                'max_tokens' => 2000,
            ]);

            if (! $response->successful()) {
                Log::error('CuratorAi: OpenRouter вернул ошибку', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $answer = $response->json('choices.0.message.content');

            return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
        } catch (\Throwable $e) {
            Log::error('CuratorAi: сбой связи с OpenRouter: '.$e->getMessage());

            return null;
        }
    }

    /**
     * История диалога в формате OpenAI chat (роли user/assistant).
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(User $user): array
    {
        return ChatMessage::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->take(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role === 'user' ? 'user' : 'assistant',
                'content' => (string) $m->text,
            ])
            ->values()
            ->all();
    }
}
