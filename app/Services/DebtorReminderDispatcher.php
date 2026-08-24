<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\Course;
use App\Models\DebtReminder;
use App\Models\User;
use App\Support\MessagePlaceholders;
use Illuminate\Support\Facades\Mail;

/**
 * Единая точка отправки напоминания должнику: рендер плейсхолдеров и доставка
 * по доступным каналам (TG/VK/email). Используется и ручной кнопкой в разделе
 * «Должники», и авто-командой debts:remind.
 *
 * DEFAULT_TEXT/DEFAULT_SUBJECT — стадия 1 («мягкое напоминание») лестницы
 * эскалации H1289; остальные стадии — App\Support\DunningStage. Закрывающая
 * строка «просто проигнорируйте» обязана оставаться в каждом шаблоне стадии:
 * в мессенджерах (TG/VK) нет email-футера, текст — единственный носитель.
 */
class DebtorReminderDispatcher
{
    /** Плейсхолдеры: {name}, {course}, {block}, {pay_link}, {paid_until}, {deadline}. */
    public const DEFAULT_TEXT = "Намасте, {name}!\n\nБлок №{block} курса «{course}» уже идет или скоро начнется, а оплата пока не поступила.{paid_until}{deadline}\n\nОплатить курс: {pay_link}\n\nЕсли оплата уже внесена — просто проигнорируйте это сообщение.";

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
     *
     * $logSource (H3156) — записать факт контакта в `debt_reminders` с этим
     * источником, но ТОЛЬКО если хоть один канал реально ушёл. Opt-in, а не
     * дефолт: у авто-команды свой вызов create (ей нужен ещё и дедуп), а у
     * реактивации — свой журнал `debt_win_back_attempts`, и двойная запись
     * превратила бы одно письмо в два «контакта» для правила H2746.
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
        ?string $logSource = null,
    ): bool {
        $hasTg = $toTelegram && ! empty($user->telegram_id);
        $hasVk = $toVk && ! empty($user->vk_id);
        $hasEmail = $toEmail && filter_var($user->email, FILTER_VALIDATE_EMAIL);

        if (! $hasTg && ! $hasVk && ! $hasEmail) {
            return false;
        }

        $course = Course::query()->whereKey($courseId)->first(['id', 'slug', 'title']);

        $replacements = MessagePlaceholders::forUser($user, $course, $blockNumber, $paidUntilLabel, $deadlineLabel);

        $rendered = MessagePlaceholders::render($textTpl, $replacements);

        if ($hasTg || $hasVk) {
            SendMessengerAlerts::dispatch($user, $rendered, $hasTg, $hasVk);
        }
        if ($hasEmail) {
            $subject = strtr($subjectTpl, $replacements);
            Mail::to($user->email)->queue(new DebtorReminderMail($subject, $rendered, $user->name));
        }

        if ($logSource !== null) {
            DebtReminder::create([
                'user_id' => $user->id,
                'course_id' => $courseId,
                'block_number' => $blockNumber,
                'sent_at' => now(),
                'source' => $logSource,
            ]);
        }

        return true;
    }
}
