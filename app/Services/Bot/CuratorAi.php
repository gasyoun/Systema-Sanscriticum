<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Jobs\OllamaShadowReplyJob;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Единый «мозг» ИИ-куратора для обоих ботов (TG/VK). Инкапсулирует провайдера —
 * любой OpenAI-совместимый chat-completions API (OpenRouter, прямой DeepSeek…),
 * base_url задаётся в services.openrouter. Контроллеры лишь сохраняют входящее
 * сообщение и шлют ответ в свой канал, а думает — этот класс.
 */
class CuratorAi
{
    /** Сколько последних сообщений диалога класть в контекст. */
    private const HISTORY_LIMIT = 15;

    public function __construct(private BotKnowledgeBase $knowledgeBase) {}

    /**
     * Сгенерировать ответ студенту. Подразумевается, что входящее сообщение
     * УЖЕ сохранено в ChatMessage вызывающим контроллером (оно попадёт в
     * историю последним). Возвращает текст ответа или null при ошибке/отказе.
     *
     * H3234 (issue #1633 этап 6): features.bot_local_generation=true → ходим
     * ТОЛЬКО в локальный Ollama через туннель (knowledge.generation_model) и
     * НИКОГДА не откатываемся в OpenRouter/DeepSeek — иначе приватность
     * превращается в «приватность, пока работает туннель». null → контроллеры
     * отвечают детерминированно (StudentSelfService + «позови куратора»).
     *
     * H3234 (этап 5): при features.bot_ollama_shadow=true успешный онлайн
     * (OpenRouter) ответ дублируется в тень — Horizon-job гоняет ТОТ ЖЕ промпт
     * через локальную модель и пишет результат в SupportAiReplyEvent
     * (ollama_shadow) рядом с онлайн-логом; неделя живого сравнения до флипа.
     */
    public function reply(User $user, string $incomingText): ?string
    {
        if (config('features.bot_local_generation', false)) {
            return $this->localReply($user, $incomingText);
        }

        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            Log::error('CuratorAi: OPENROUTER_API_KEY не задан');

            return null;
        }

        $messages = $this->messagesFor($user, $incomingText);
        $result = $this->chatWithUsage($messages);
        $answer = $result['content'];

        if ($answer !== null && config('features.bot_ollama_shadow', false)) {
            OllamaShadowReplyJob::dispatch($user->id, $messages, $incomingText);
        }

        return $answer;
    }

    /**
     * H3234 (этап 6): локальная генерация. Ollama отдаёт OpenAI-совместимый
     * /v1/chat/completions (config/knowledge.php: base_url + generation_model),
     * поэтому «мозг» подключается сменой конфига. Любой сбой узла (туннель
     * умер, модель не отвечает) → null и ни одного байта наружу.
     */
    public function localReply(User $user, string $incomingText): ?string
    {
        return $this->localChatWithUsage($this->messagesFor($user, $incomingText))['content'];
    }

    /**
     * H3234 (этап 5): сообщения для генерации (системный промпт + история) —
     * общая точка онлайн-пути, локальной генерации и тени: тень обязана видеть
     * ТОТ ЖЕ промпт, что ушёл в OpenRouter.
     *
     * @return list<array{role: string, content: string}>
     */
    public function messagesFor(User $user, string $incomingText): array
    {
        return array_merge(
            [['role' => 'system', 'content' => $this->knowledgeBase->systemPrompt($incomingText)]],
            $this->history($user),
        );
    }

    /**
     * H3234 (этапы 5–6): локальный chat-completions. Отдельно от
     * chatWithUsage(): другой base_url, модель, таймаут и — главное —
     * гарантия отсутствия внешнего fallback. Сбои мягкие: null, не исключение.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: ?string, usage: ?array{prompt_tokens: int, completion_tokens: int}, model: ?string}
     */
    public function localChatWithUsage(array $messages): array
    {
        $model = (string) config('knowledge.generation_model', 'qwen3:14b');
        $empty = ['content' => null, 'usage' => null, 'model' => $model];

        $endpoint = rtrim((string) config('knowledge.base_url', 'http://127.0.0.1:11434'), '/').'/v1/chat/completions';

        try {
            $response = Http::timeout((int) config('knowledge.generation_timeout', 120))
                ->post($endpoint, [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.3,
                    'max_tokens' => 2000,
                ]);

            if (! $response->successful()) {
                Log::warning('CuratorAi: локальный узел вернул ошибку', [
                    'status' => $response->status(),
                    'model' => $model,
                ]);

                return $empty;
            }

            $answer = $response->json('choices.0.message.content');
            $content = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;

            $promptTokens = $response->json('usage.prompt_tokens');
            $completionTokens = $response->json('usage.completion_tokens');
            $usage = is_int($promptTokens) && is_int($completionTokens)
                ? ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens]
                : null;

            return ['content' => $content, 'usage' => $usage, 'model' => $response->json('model', $model)];
        } catch (\Throwable $e) {
            Log::warning('CuratorAi: локальный узел недоступен (деградация в детерминированный режим): '.$e->getMessage());

            return $empty;
        }
    }

    /**
     * Низкоуровневый вызов LLM с готовым набором сообщений (OpenAI chat format).
     * Переиспользуется ИИ-ассистом поддержки поверх единого треда. Возвращает
     * текст ответа или null при ошибке/отказе. Тонкая обёртка над
     * {@see chatWithUsage()} для существующих вызывающих кодов, которым не
     * нужны токены/модель.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function chat(array $messages): ?string
    {
        return $this->chatWithUsage($messages)['content'];
    }

    /**
     * Как {@see chat()}, но также возвращает usage (токены) и имя модели —
     * нужно для расхода LLM на дашборде наблюдаемости (W3.3 follow-up, H763).
     * OpenRouter отдаёт `usage.prompt_tokens`/`usage.completion_tokens` в
     * каждом успешном ответе; раньше эта часть тела просто отбрасывалась.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: ?string, usage: ?array{prompt_tokens: int, completion_tokens: int}, model: ?string}
     */
    public function chatWithUsage(array $messages): array
    {
        $model = config('services.openrouter.model', 'deepseek/deepseek-v4-flash');
        $empty = ['content' => null, 'usage' => null, 'model' => $model];

        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            Log::error('CuratorAi: OPENROUTER_API_KEY не задан');

            return $empty;
        }

        $endpoint = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/').'/chat/completions';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
                // Рекомендованные OpenRouter заголовки атрибуции (ASCII);
                // для других OpenAI-совместимых провайдеров безвредны.
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => 'Academy of Sanskrit (ORS) bot',
            ])->timeout(45)->post($endpoint, [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.3,
                'max_tokens' => 2000,
            ]);

            if (! $response->successful()) {
                Log::error('CuratorAi: OpenRouter вернул ошибку', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $empty;
            }

            $answer = $response->json('choices.0.message.content');
            $content = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;

            $promptTokens = $response->json('usage.prompt_tokens');
            $completionTokens = $response->json('usage.completion_tokens');
            $usage = is_int($promptTokens) && is_int($completionTokens)
                ? ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens]
                : null;

            return ['content' => $content, 'usage' => $usage, 'model' => $response->json('model', $model)];
        } catch (\Throwable $e) {
            Log::error('CuratorAi: сбой связи с OpenRouter: '.$e->getMessage());

            return $empty;
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
