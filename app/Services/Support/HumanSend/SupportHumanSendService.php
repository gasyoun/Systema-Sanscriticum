<?php

declare(strict_types=1);

namespace App\Services\Support\HumanSend;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Services\Support\SupportHintSendButton;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * H6183 (пакет hermes-24-01a10bca): проверяемый human-send в TG и web.
 *
 * Единственная точка, через которую черновик уходит студенту по нажатию
 * человека в ОБОИХ транспортах. Инварианты миссии, каждый ломался в этом
 * контуре раньше, поэтому каждый держится отдельной защёлкой:
 *
 *  1) «только аутентифицированное действие человека разрешает send» — вход
 *     только с {@see HumanSendAuthorization}; agent-вид получает отказ БЕЗ
 *     единой записи (статус черновика не тронут, ничего не поставлено);
 *  2) «повтор approval не отправляет второе сообщение» — статус pending
 *     бывает один раз; второе нажатие видит already;
 *  3) «повтор webhook не отправляет второе сообщение» — TG-дорожка идёт
 *     через существующий {@see SupportHintSendButton::deliver()} с его
 *     двухслойным дедупом (статус + клейм TelegramSendGuard); web-дорожка
 *     берёт клейм по (конвертация, текст) тем же guard'ом.
 *
 * Доставка — «mocked» по построению: сервис пишет ТОЛЬКО в стор (pending
 * TelegramSupportMessage — увозит существующий telegram-support:sync;
 * ChatMessage + broadcast ChatMessageSent — забирает виджет web-чата) и не
 * делает ни одного сетевого вызова.
 *
 * Фича-флаг features.hermes_human_send (env HERMES_HUMAN_SEND) ВЫКЛ по
 * умолчанию: включение — deploy-шаг человека, как у роли 23 (H6187).
 */
final class SupportHumanSendService
{
    /** Событие аудита: человек отправил черновик через TG-дорожку. */
    public const EVENT_HUMAN_SEND_TG = 'human_send_tg';

    /** Событие аудита: человек отправил черновик в web-чат. */
    public const EVENT_HUMAN_SEND_WEB = 'human_send_web';

    /** Окно подавления дубля web-отправки (конвертация, текст). */
    private const WEB_DEDUP_TTL_SECONDS = 86400;

    public function __construct(private readonly SupportHintSendButton $hintButton) {}

    /**
     * Отправить черновик человеком. Возвращает машинно-читаемый исход:
     * sent | suppressed | already | disabled | refused_agent | no_source |
     * unsupported_source | queue_failed (+ co-статусы TG-делегации).
     *
     * @return array{status: string, message: string}
     */
    public function send(SupportAnswerSuggestion $draft, HumanSendAuthorization $actor): array
    {
        if (! config('features.hermes_human_send', false)) {
            return ['status' => 'disabled', 'message' => 'Human-send выключен (HERMES_HUMAN_SEND).'];
        }

        if (! $actor->isHuman()) {
            // Агентский вызов: отказ без единой записи — ни статуса, ни стор.
            Log::warning('SupportHumanSendService: агентный вызов send отклонён', [
                'suggestion_id' => $draft->id,
                'actor' => $actor->describe(),
            ]);

            return ['status' => 'refused_agent', 'message' => 'Отправка разрешена только человеку.'];
        }

        if ($draft->status !== SupportAnswerSuggestion::STATUS_PENDING) {
            // Повтор approval (двойной клик, повторная страница): pending
            // бывает один раз — второй раз уже нечего отправлять.
            return ['status' => 'already', 'message' => 'Уже отправлено.'];
        }

        return match ($draft->source_type) {
            SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE => $this->sendViaTelegram($draft, $actor),
            SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE => $this->sendViaWeb($draft, $actor),
            default => ['status' => 'unsupported_source', 'message' => 'Неизвестный источник черновика.'],
        };
    }

    /**
     * TG-дорожка: делегируем ЕДИНОЙ существующей точке отправки черновика —
     * у неё уже есть двухслойный дедуп (статус + TelegramSendGuard по
     * (чат, текст)) и webhook-ределивери глушится контроллером по update_id.
     * Повторный проход сюда невозможен: deliver сам перевёл статус.
     *
     * @return array{status: string, message: string}
     */
    private function sendViaTelegram(SupportAnswerSuggestion $draft, HumanSendAuthorization $actor): array
    {
        return $this->hintButton->deliver(
            $draft,
            $actor->userId(),
            self::EVENT_HUMAN_SEND_TG,
            [
                'human' => $actor->describe(),
                'transport' => 'telegram',
            ],
        );
    }

    /**
     * Web-дорожка (chat_message): исходящее curator-сообщение в конвертацию
     * исходного вопроса + broadcast виджету. Дедуп двухслойный, как в TG:
     * статус pending + клейм по (конвертация, текст).
     *
     * @return array{status: string, message: string}
     */
    private function sendViaWeb(SupportAnswerSuggestion $draft, HumanSendAuthorization $actor): array
    {
        $source = ChatMessage::query()->find((int) $draft->source_id);

        if ($source === null || $source->support_conversation_id === null) {
            return ['status' => 'no_source', 'message' => 'Исходное сообщение web-чата не найдено.'];
        }

        $conversationId = (int) $source->support_conversation_id;
        $text = (string) $draft->draft_text;

        if (! $this->claimWebSend($conversationId, $text)) {
            // Тот же текст в ту же конвертацию уже уходил внутри окна дедупа.
            $this->resolveDraft($draft, $actor, SupportAnswerSuggestion::STATUS_ACCEPTED);

            return ['status' => 'suppressed', 'message' => 'Уже отправлено.'];
        }

        try {
            $message = ChatMessage::create([
                'support_conversation_id' => $conversationId,
                'user_id' => $draft->user_id,
                'role' => 'curator',
                'answered_by' => $actor->userId(),
                'text' => $text,
                'is_read' => false,
            ]);
        } catch (Throwable $exception) {
            // Отказ известен точно — клейм отпускаем, повтор осмыслен.
            $this->releaseWebSend($conversationId, $text);

            Log::warning('SupportHumanSendService: не удалось записать web-исходящее', [
                'suggestion_id' => $draft->id,
                'conversation_id' => $conversationId,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 'queue_failed', 'message' => 'Не удалось отправить — попробуйте ещё раз.'];
        }

        event(new ChatMessageSent($message));

        $this->resolveDraft(
            $draft,
            $actor,
            (is_array($draft->facts) && ($draft->facts['edited'] ?? false))
                ? SupportAnswerSuggestion::STATUS_EDITED
                : SupportAnswerSuggestion::STATUS_ACCEPTED,
        );

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => self::EVENT_HUMAN_SEND_WEB,
            'meta' => [
                'via' => 'human_send',
                'suggestion_id' => $draft->id,
                'category' => $draft->category,
                'send_policy' => $draft->sendPolicy(),
                'transport' => 'web',
                'human' => $actor->describe(),
                'chat_message_id' => $message->id,
                'support_conversation_id' => $conversationId,
            ],
        ]);

        return ['status' => 'sent', 'message' => 'Отправлено ✅'];
    }

    /** Перевод статуса ровно один раз — теми же полями, что и TG-дорожка. */
    private function resolveDraft(SupportAnswerSuggestion $draft, HumanSendAuthorization $actor, string $status): void
    {
        $draft->forceFill([
            'status' => $status,
            'resolved_by' => $actor->userId(),
            'resolved_at' => now(),
        ])->save();
    }

    private function claimWebSend(int $conversationId, string $text): bool
    {
        // Тот же клейм-контракт, что у TG-дорожки: NX-ключ на окно дедупа,
        // fail-open при недоступном Redis (дедуп — не гейт доставки).
        return TelegramSendGuard::claimKey(
            $this->webDedupKey($conversationId, $text),
            self::WEB_DEDUP_TTL_SECONDS,
        );
    }

    private function releaseWebSend(int $conversationId, string $text): void
    {
        try {
            Redis::del($this->webDedupKey($conversationId, $text));
        } catch (Throwable) {
            // Клейм истечёт сам; потеря ключа на TTL дешевле потери сообщения.
        }
    }

    private function webDedupKey(int $conversationId, string $text): string
    {
        return 'human_send:web:'.hash('sha256', $conversationId."\x00".$text);
    }
}
