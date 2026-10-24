<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Jobs\OllamaShadowReplyJob;
use App\Models\ChatMessage;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Support\Faq\HybridRetriever;
use App\Services\Support\SupportQuestionCategorizer;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Единый «мозг» ИИ-куратора для обоих ботов (TG/VK). Инкапсулирует провайдера —
 * любой OpenAI-совместимый chat-completions API (OpenRouter, прямой DeepSeek…),
 * base_url задаётся в services.openrouter. Контроллеры лишь сохраняют входящее
 * сообщение и шлют ответ в свой канал, а думает — этот класс.
 *
 * H6352-r3 (рулинг 5 грилла 10-10): перед ЛЮБЫМ внешним вызовом промпт проходит
 * через {@see SupportStudentPiiMasker} — личности студентов (имя, почта,
 * телефон, TG-хэндл, VK id) уходят провайдеру только псевдонимами <PII:…:N>, а
 * ответ восстанавливается обратно. Маскировщик недоступен → внешнего вызова
 * нет (fail-closed), бот отвечает детерминированно.
 */
class CuratorAi
{
    /** Сколько последних сообщений диалога класть в контекст. */
    private const HISTORY_LIMIT = 15;

    public function __construct(
        private BotKnowledgeBase $knowledgeBase,
        private SupportStudentPiiMasker $piiMasker,
        private HybridRetriever $faq,
    ) {}

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
        // 0YW (RAG grill card 9): F-only citation for the cabinet bot. Deterministic,
        // local, no provider call — same floor domain and hard {F} allowlist as the
        // TG/webchat lanes. OFF by default; the flip is a human decision (R9/R10).
        $citation = $this->faqCitation($incomingText);
        if ($citation !== null) {
            return $citation;
        }

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
     * F-only FAQ citation for the cabinet bot (0YW).
     *
     * The category is re-derived from the text (never taken from the caller) and
     * must be exactly F; D/E are excluded by the allowlist, not by config. The
     * score floor is the same support.faq_rag.shadow_min_score_by_category.F that
     * the TG and webchat lanes read, so a calibration change moves all three.
     */
    private function faqCitation(string $text): ?string
    {
        if (! (bool) config('features.bot_faq_live_f', false)) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (SupportQuestionCategorizer::categorize($text) !== SupportAnswerSuggestion::CATEGORY_MATERIALS) {
            return null;
        }

        $hits = $this->faq->retrieve($text, 3);
        if ($hits === []) {
            return null;
        }

        $score = HybridRetriever::bm25Score($hits[0]);
        $floor = (float) config('support.faq_rag.shadow_min_score_by_category.'.SupportAnswerSuggestion::CATEGORY_MATERIALS, 15.7);
        if ($score < $floor) {
            return null;
        }

        $snippet = trim((string) ($hits[0]['snippet'] ?? ''));
        if ($snippet === '') {
            return null;
        }

        $title = trim((string) ($hits[0]['title'] ?? ''));
        $citation = $title === '' ? 'наш FAQ' : "наш FAQ, раздел «{$title}»";

        return "Намасте!\n\n{$snippet}\n\nИсточник — {$citation}. Если вопрос остался, напишите — ответит куратор.";
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
    public function localChatWithUsage(array $messages, ?int $timeout = null, float $temperature = 0.3, bool $think = true, int $maxTokens = 2000): array
    {
        $model = (string) config('knowledge.generation_model', 'qwen3:14b');
        $empty = ['content' => null, 'usage' => null, 'model' => $model];

        // H5703: генерация может жить на другом узле, чем эмбеддинги
        // (generation_base_url); не задан → общий base_url, прод не меняется.
        $base = (string) (config('knowledge.generation_base_url') ?: config('knowledge.base_url', 'http://127.0.0.1:11434'));
        $endpoint = rtrim($base, '/').'/v1/chat/completions';

        try {
            $response = Http::timeout($timeout ?? (int) config('knowledge.generation_timeout', 120))
                ->post($endpoint, [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    // qwen3 без рассуждений: ответ за 1–3 с вместо 30 (Ollama ≥ 0.9).
                    ...($think ? [] : ['reasoning_effort' => 'none']),
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

        // H6352-r3: во внешний провайдер уходит только замаскированный промпт.
        // Одна область псевдонимов на вызов — один человек = один тег во всех
        // сообщениях, ответ восстанавливается тем же словарём (unmask).
        //
        // H6918-2: сообщения — это ОДНА беседа, поэтому маскируем их одним
        // проходом. maskAll() сначала собирает per-conversation словарь имён и
        // ников по всем сообщениям, затем маскирует: «Мария Ковалёва» в
        // системном/первом сообщении закрывает bare «Ковалёва» в следующем.
        try {
            $this->piiMasker->newConversation();
            $maskedContents = $this->piiMasker->maskAll(array_map(
                static fn (array $message): string => (string) ($message['content'] ?? ''),
                $messages,
            ));
            $maskedMessages = [];
            foreach ($messages as $index => $message) {
                $maskedMessages[] = [
                    'role' => (string) ($message['role'] ?? 'user'),
                    'content' => $maskedContents[$index] ?? '',
                ];
            }
        } catch (\Throwable) {
            // Fail-closed: чистоту промпта доказать нельзя — внешнего вызова нет.
            Log::error('CuratorAi: PII-маскировщик недоступен — вызов OpenRouter отменён');

            return $empty;
        }

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
                'messages' => $maskedMessages,
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
            $content = is_string($answer) && trim($answer) !== ''
                ? $this->piiMasker->unmask(trim($answer))
                : null;

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
