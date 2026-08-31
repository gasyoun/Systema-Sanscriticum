<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Log;

/**
 * H3765 A5 (рулинг R8): кнопка «Отправить как есть» под подсказкой куратору.
 *
 * Зачем. Черновики жили в /admin, и за две недели их открыли НОЛЬ раз —
 * кураторы живут в Telegram, а не в админке. Подсказка и так приходит им в
 * Telegram; не хватало одного нажатия, чтобы готовый ответ ушёл студенту.
 *
 * Что делает нажатие: проверяет, что нажал получатель подсказки этого
 * аккаунта (или админ), ставит черновик в исходящую очередь тем же
 * {@see SupportReplyService::queueAiReply}, которым пользуется автоответчик,
 * и помечает {@see SupportAnswerSuggestion} принятым. Доставку увозит
 * ближайший заход telegram-support:sync — отдельной точки отправки в Telegram
 * здесь не появляется.
 *
 * Дедуп двухслойный, и оба слоя обязательны:
 *  1) статус черновика — pending бывает один раз, второе нажатие видит accepted;
 *  2) {@see TelegramSendGuard} claim по (чат, текст) — фенс репозитория требует
 *     клейм перед ЛЮБОЙ новой точкой отправки (инцидент 24-08-2026), и он же
 *     ловит случай, когда тот же текст уже уехал другой дорогой.
 */
final class SupportHintSendButton
{
    public function __construct(
        private readonly SupportReplyService $replies,
        private readonly TelegramAdminNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $callback  сырой callback_query Telegram
     */
    public function handle(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $tapperId = $callback['from']['id'] ?? null;

        $suggestionId = (int) substr($data, strlen(SupportDmAutoReply::SEND_CALLBACK_PREFIX));

        $suggestion = SupportAnswerSuggestion::query()->find($suggestionId);
        if ($suggestion === null || $tapperId === null) {
            $this->notifier->answerCallback($callbackId, 'Черновик не найден.');

            return;
        }

        $incoming = TelegramSupportMessage::query()->find((int) $suggestion->source_id);
        if ($incoming === null) {
            $this->notifier->answerCallback($callbackId, 'Исходное сообщение не найдено.');

            return;
        }

        if (! $this->isHintRecipient((string) $tapperId, $incoming)) {
            // Молча-вежливо: посторонний не должен узнать, что кнопка рабочая.
            $this->notifier->answerCallback($callbackId, 'Недостаточно прав.');

            return;
        }

        if ($suggestion->status !== SupportAnswerSuggestion::STATUS_PENDING) {
            $this->notifier->answerCallback($callbackId, 'Уже отправлено.');

            return;
        }

        $maxAgeDays = max(1, (int) config('support.hint_send_button_max_age_days', 7));
        if ($suggestion->created_at !== null && $suggestion->created_at->lt(now()->subDays($maxAgeDays))) {
            // Пролистали старый чат и нажали — отвечать студенту нечего.
            $suggestion->forceFill(['status' => SupportAnswerSuggestion::STATUS_EXPIRED])->save();

            $this->notifier->answerCallback($callbackId, "Черновик старше {$maxAgeDays} дн. — ответьте вручную.");

            return;
        }

        $student = $suggestion->user_id === null ? null : User::query()->find($suggestion->user_id);
        if ($student === null) {
            $this->notifier->answerCallback($callbackId, 'Студент не привязан — ответьте вручную.');

            return;
        }

        $draft = (string) $suggestion->draft_text;
        $chatId = (string) $incoming->telegram_chat_id;

        if (! TelegramSendGuard::claim($chatId, $draft)) {
            // Тот же текст в тот же чат уже уходил внутри окна дедупа.
            $suggestion->forceFill([
                'status' => SupportAnswerSuggestion::STATUS_ACCEPTED,
                'resolved_by' => $this->tapperUserId((string) $tapperId),
                'resolved_at' => now(),
            ])->save();

            $this->notifier->answerCallback($callbackId, 'Уже отправлено.');

            return;
        }

        $outgoing = $this->replies->queueAiReply($student, $draft, (int) $incoming->telegram_message_id);

        if ($outgoing === null) {
            // Отказ известен точно — доставки не было, клейм отпускаем, чтобы
            // повторное нажатие имело шанс.
            TelegramSendGuard::release($chatId, $draft);

            Log::warning('SupportHintSendButton: не удалось поставить pending исходящее', [
                'suggestion_id' => $suggestion->id,
                'user_id' => $student->id,
            ]);

            $this->notifier->answerCallback($callbackId, 'Не удалось поставить в очередь — ответьте вручную.');

            return;
        }

        $suggestion->forceFill([
            'status' => SupportAnswerSuggestion::STATUS_ACCEPTED,
            'resolved_by' => $this->tapperUserId((string) $tapperId),
            'resolved_at' => now(),
        ])->save();

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $outgoing->id,
            'event_type' => SupportDmAutoReply::EVENT_HINT_SEND_TAPPED,
            'meta' => [
                'via' => SupportDmAutoReply::VIA,
                'suggestion_id' => $suggestion->id,
                'category' => $suggestion->category,
                'tapped_by_telegram_id' => (string) $tapperId,
                'source_telegram_message_id' => (int) $incoming->telegram_message_id,
            ],
        ]);

        $this->notifier->answerCallback($callbackId, 'Отправлено ✅');
    }

    /**
     * Нажавший должен быть получателем подсказок ЭТОГО аккаунта (H3393) либо
     * админом. Список получателей — тот же, по которому подсказка и ушла.
     */
    private function isHintRecipient(string $tapperId, TelegramSupportMessage $incoming): bool
    {
        /** @var TelegramSupportAccount|null $account */
        $account = TelegramSupportAccount::query()->find($incoming->telegram_support_account_id);
        $recipients = $account?->hint_recipients;
        $recipients = is_array($recipients) ? array_map('strval', $recipients) : [];

        return in_array($tapperId, $recipients, true)
            || in_array($tapperId, $this->notifier->adminChatIds(), true);
    }

    private function tapperUserId(string $tapperId): ?int
    {
        return User::query()->where('telegram_id', $tapperId)->value('id');
    }
}
