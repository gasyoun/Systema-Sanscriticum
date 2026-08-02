<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Mail\CertificateIssuedMail;
use App\Models\Certificate;
use Illuminate\Support\Facades\Mail;

/**
 * Уведомление студента о выданном по вехе сертификате: TG/VK + письмо со
 * ссылкой на кабинет (PDF генерируется на лету при скачивании — вложений нет).
 * Дедуп — certificates.notified_at (паттерн recruitment_notified_at): команда
 * certificates:issue-milestones добирает неуведомлённых на следующем прогоне.
 *
 * Ручные сертификаты (certificate_milestone_id NULL) не рассылаются — поведение
 * ручного контура не меняется.
 */
class CertificateIssuedNotifier
{
    /** Отправить и проставить notified_at. true — ушёл хотя бы один канал. */
    public function notify(Certificate $certificate): bool
    {
        if ($certificate->certificate_milestone_id === null || $certificate->notified_at !== null) {
            return false;
        }

        $user = $certificate->user;
        if (! $user) {
            return false;
        }

        $hasTg = ! empty($user->telegram_id);
        $hasVk = ! empty($user->vk_id);
        $hasEmail = filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false;

        if (! $hasTg && ! $hasVk && ! $hasEmail) {
            return false;
        }

        if ($hasTg || $hasVk) {
            $title = str_replace('|', ' ', $certificate->displayCourseTitle());
            $issued = $certificate->isSpravka()
                ? 'вам выдана справка об образовании'
                : 'вам выдан сертификат';
            $text = "🎓 Намасте, {$user->name}!\n\n"
                ."Поздравляем — {$issued} «{$title}» (№ {$certificate->number}).\n\n"
                .'Скачать документ можно в кабинете: '.route('student.dashboard');
            SendMessengerAlerts::dispatch($user, $text, $hasTg, $hasVk);
        }

        if ($hasEmail) {
            Mail::to($user->email)->queue(new CertificateIssuedMail($certificate));
        }

        $certificate->forceFill(['notified_at' => now()])->save();

        return true;
    }
}
