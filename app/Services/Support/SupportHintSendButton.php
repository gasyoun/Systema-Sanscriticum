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
 *
 * H3999 (шаг I1a/I1b). Черновики теперь бывают не только из FAQ, и часть из них
 * отправлять одним нажатием НЕЛЬЗЯ вообще: остаток по оплате, состояние доступа,
 * сертификат (рулинг A1). Кнопки под такими подсказками не появляется, но это
 * оформление, а не защита — защита здесь: {@see self::deliver()} отказывает
 * draft-only черновику на дорожке из Telegram и пропускает его только из очереди
 * черновиков админки, где куратор видит текст целиком и правит его перед
 * отправкой. Резолвер МЕТИТ, отправитель РЕШАЕТ.
 *
 * Та же {@see self::deliver()} — единственная точка отправки черновика и для
 * очереди в админке: два разных кода «отправить черновик» разошлись бы по
 * клейму или по статусу в первый же месяц.
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

        if ($suggestion->isDraftOnly()) {
            // Рулинг A1: деньги, доступ и сертификат уходят студенту только
            // через очередь черновиков, где куратор читает текст целиком.
            $this->notifier->answerCallback($callbackId, 'Требует проверки — откройте очередь черновиков в админке.');

            return;
        }

        $maxAgeDays = max(1, (int) config('support.hint_send_button_max_age_days', 7));
        if ($suggestion->created_at !== null && $suggestion->created_at->lt(now()->subDays($maxAgeDays))) {
            // Пролистали старый чат и нажали — отвечать студенту нечего.
            $suggestion->forceFill(['status' => SupportAnswerSuggestion::STATUS_EXPIRED])->save();

            $this->notifier->answerCallback($callbackId, "Черновик старше {$maxAgeDays} дн. — ответьте вручную.");

            return;
        }

        $result = $this->deliver(
            $suggestion,
            $this->tapperUserId((string) $tapperId),
            SupportDmAutoReply::EVENT_HINT_SEND_TAPPED,
            ['tapped_by_telegram_id' => (string) $tapperId],
        );

        $this->notifier->answerCallback($callbackId, $result['message']);
    }

    /**
     * Отправить черновик студенту. ЕДИНСТВЕННАЯ точка отправки черновика —
     * и для кнопки под подсказкой, и для очереди черновиков в админке.
     *
     * Инварианты (все три уже ломались в этом контуре):
     *  1) клейм {@see TelegramSendGuard} строго ДО постановки исходящего —
     *     фенс репозитория после инцидента 24-08-2026;
     *  2) точно известный отказ отпускает клейм (повтор осмыслен), а
     *     подавленный клейм закрывает черновик как отправленный;
     *  3) статус черновика меняется ровно один раз.
     *
     * @param  array<string, mixed>  $metaExtra
     * @return array{status: string, message: string}
     */
    public function deliver(
        SupportAnswerSuggestion $suggestion,
        ?int $resolvedBy,
        string $eventType,
        array $metaExtra = [],
    ): array {
        $incoming = TelegramSupportMessage::query()->find((int) $suggestion->source_id);

        if ($incoming === null) {
            return ['status' => 'no_source', 'message' => 'Исходное сообщение не найдено.'];
        }

        if ($suggestion->status !== SupportAnswerSuggestion::STATUS_PENDING) {
            return ['status' => 'already', 'message' => 'Уже отправлено.'];
        }

        $student = $suggestion->user_id === null ? null : User::query()->find($suggestion->user_id);

        if ($student === null) {
            return ['status' => 'unlinked', 'message' => 'Студент не привязан — ответьте вручную.'];
        }

        $draft = (string) $suggestion->draft_text;
        $chatId = (string) $incoming->telegram_chat_id;

        if (! TelegramSendGuard::claim($chatId, $draft)) {
            // Тот же текст в тот же чат уже уходил внутри окна дедупа.
            $suggestion->forceFill([
                'status' => SupportAnswerSuggestion::STATUS_ACCEPTED,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
            ])->save();

            return ['status' => 'suppressed', 'message' => 'Уже отправлено.'];
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

            return ['status' => 'queue_failed', 'message' => 'Не удалось поставить в очередь — ответьте вручную.'];
        }

        $suggestion->forceFill([
            // Правка в очереди черновиков НЕ меняет статус (иначе черновик
            // перестал бы быть pending и отправить его стало бы нельзя) —
            // она ставит метку в facts, и итоговый статус читает её здесь.
            'status' => (is_array($suggestion->facts) && ($suggestion->facts['edited'] ?? false))
                ? SupportAnswerSuggestion::STATUS_EDITED
                : SupportAnswerSuggestion::STATUS_ACCEPTED,
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
        ])->save();

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $outgoing->id,
            'event_type' => $eventType,
            'meta' => [
                'via' => SupportDmAutoReply::VIA,
                'suggestion_id' => $suggestion->id,
                'category' => $suggestion->category,
                'send_policy' => $suggestion->sendPolicy(),
                'source_telegram_message_id' => (int) $incoming->telegram_message_id,
                ...$metaExtra,
            ],
        ]);

        return ['status' => 'sent', 'message' => 'Отправлено ✅'];
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
