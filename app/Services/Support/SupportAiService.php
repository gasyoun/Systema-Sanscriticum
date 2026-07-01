<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Support\UnifiedMessage;

/**
 * ИИ-ассист поддержки поверх ЕДИНОГО треда (веб + TG-support). Две недостающие
 * функции из docs/support-subsystem-map.md: черновик ответа и резюме диалога.
 * Ничего не отправляет сам — только готовит текст куратору. За флагом
 * features.support_ai_assist. Каждое обращение логируется в SupportAiReplyEvent
 * (event_type suggested|summary), переиспользуя существующий AI-лог.
 */
class SupportAiService
{
    /** Сколько последних сообщений треда класть в контекст. */
    private const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly CuratorAi $ai,
        private readonly UnifiedInboxReader $reader,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_ai_assist');
    }

    /** Черновик ответа студенту по контексту всего треда. */
    public function suggestReply(User|int $user): ?string
    {
        return $this->run(
            $user,
            'suggested',
            'Ты — помощник куратора школы. По истории диалога предложи вежливый, '
                .'конкретный черновик ОТВЕТА студенту на русском. Верни только текст ответа, без пояснений.',
        );
    }

    /** Краткое резюме диалога для куратора (3–5 пунктов). */
    public function summarize(User|int $user): ?string
    {
        return $this->run(
            $user,
            'summary',
            'Ты — помощник куратора. Кратко резюмируй диалог для куратора: 3–5 пунктов '
                .'о сути обращения и текущем статусе. Только резюме, на русском.',
        );
    }

    private function run(User|int $user, string $eventType, string $systemPrompt): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $history = $this->history($user);
        if ($history === []) {
            return null;
        }

        $answer = $this->ai->chat(array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
        ));

        if ($answer === null) {
            return null;
        }

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => $eventType,
            'meta' => [
                'scope' => 'helpdesk_unified',
                'user_id' => $user instanceof User ? $user->id : $user,
                'preview' => mb_substr($answer, 0, 240),
            ],
        ]);

        return $answer;
    }

    /**
     * Единый тред в формате OpenAI chat: входящее → user, исходящее → assistant.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(User|int $user): array
    {
        return $this->reader->forUser($user)
            ->slice(-self::HISTORY_LIMIT)
            ->map(fn (UnifiedMessage $message) => [
                'role' => $message->isIncoming() ? 'user' : 'assistant',
                'content' => $message->text,
            ])
            ->values()
            ->all();
    }
}
