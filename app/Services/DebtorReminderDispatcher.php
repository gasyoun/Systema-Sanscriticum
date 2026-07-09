<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Единая точка отправки напоминания должнику: рендер плейсхолдеров и доставка
 * по доступным каналам (TG/VK/email). Используется и ручной кнопкой в разделе
 * «Должники», и авто-командой debts:remind.
 */
class DebtorReminderDispatcher
{
    /** Плейсхолдеры: {name}, {course}, {block}, {pay_link}, {paid_until}, {deadline}. */
    public const DEFAULT_TEXT = "Намасте, {name}!\n\nБлок №{block} курса «{course}» уже идёт (или скоро начнётся), а оплата ещё не поступила.{paid_until}{deadline} Чтобы не потерять доступ к материалам, оформите оплату.\n\nОплатить курс: {pay_link}";

    public const DEFAULT_SUBJECT = 'Напоминание об оплате — {course}';

    /**
     * Отправить напоминание. Возвращает true, если ушёл хотя бы один канал.
     *
     * $paidUntilLabel — «оплачено до» (блок/дата) по предыдущим реальным
     * платежам студента, если были; см. StudentDebtsService::paidUntilForUser.
     * $deadlineLabel — дедлайн следующего платежа: до 00:00 по Москве в день
     * старта блока {block} (MG rule: «до дня старта следующего модуля, до
     * 00:00 по Москве»). Оба — готовые предложения-фрагменты (ведущий пробел
     * + точка) или null/"" при отсутствии данных, подставляются в
     * {paid_until}/{deadline} в DEFAULT_TEXT.
     */
    public function send(
        User $user,
        int $courseId,
        ?int $blockNumber,
        string $textTpl,
        string $subjectTpl,
        bool $toTelegram,
        bool $toVk,
        bool $toEmail,
        ?string $paidUntilLabel = null,
        ?string $deadlineLabel = null,
    ): bool {
        $hasTg = $toTelegram && ! empty($user->telegram_id);
        $hasVk = $toVk && ! empty($user->vk_id);
        $hasEmail = $toEmail && filter_var($user->email, FILTER_VALIDATE_EMAIL);

        if (! $hasTg && ! $hasVk && ! $hasEmail) {
            return false;
        }

        $course = Course::query()->whereKey($courseId)->first(['id', 'slug', 'title']);

        $replacements = \App\Support\MessagePlaceholders::forUser($user, $course, $blockNumber, $paidUntilLabel, $deadlineLabel);

        $rendered = \App\Support\MessagePlaceholders::render($textTpl, $replacements);

        if ($hasTg || $hasVk) {
            SendMessengerAlerts::dispatch($user, $rendered, $hasTg, $hasVk);
        }
        if ($hasEmail) {
            $subject = strtr($subjectTpl, $replacements);
            Mail::to($user->email)->queue(new DebtorReminderMail($subject, $rendered, $user->name));
        }

        return true;
    }
}
