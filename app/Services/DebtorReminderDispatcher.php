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
    /** Плейсхолдеры: {name}, {course}, {block}, {pay_link}. */
    public const DEFAULT_TEXT = "Намасте, {name}!\n\nБлок №{block} курса «{course}» уже идёт (или скоро начнётся), а оплата ещё не поступила. Чтобы не потерять доступ к материалам, оформите оплату.\n\nОплатить курс: {pay_link}";

    public const DEFAULT_SUBJECT = 'Напоминание об оплате — {course}';

    /**
     * Отправить напоминание. Возвращает true, если ушёл хотя бы один канал.
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
    ): bool {
        $hasTg = $toTelegram && ! empty($user->telegram_id);
        $hasVk = $toVk && ! empty($user->vk_id);
        $hasEmail = $toEmail && filter_var($user->email, FILTER_VALIDATE_EMAIL);

        if (! $hasTg && ! $hasVk && ! $hasEmail) {
            return false;
        }

        $course = Course::query()->whereKey($courseId)->first(['id', 'slug', 'title']);
        $slug = $course?->slug;

        $replacements = [
            '{name}' => $user->name ?: 'Друг',
            '{course}' => $course?->title ?? '',
            '{block}' => (string) ($blockNumber ?? ''),
            '{pay_link}' => $slug ? route('student.course', $slug) : url('/login'),
        ];

        $rendered = strtr($textTpl, $replacements);

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
