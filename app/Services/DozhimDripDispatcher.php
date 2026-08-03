<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Support\MessagePlaceholders;
use Illuminate\Support\Facades\Mail;

/**
 * Отправка одного шага дожим-дрипа (H2059) по сделке — TG/VK/email, теми же
 * каналами и плейсхолдерами, что {@see DebtorReminderDispatcher}. Отдельный
 * класс, а не переиспользование DebtorReminderDispatcher::send() напрямую:
 * тот требует int $courseId (Deal.course_id нередко null), и предмет письма
 * там — оплата долга, а не текст шаблона дожима.
 *
 * DebtorReminderMail переиспользуется как есть — тело/тема уже параметризованы
 * вызывающим кодом, «должник» в имени не про смысл письма, а про исходную
 * фичу.
 */
class DozhimDripDispatcher
{
    /** Отправить шаблон адресату сделки. Возвращает true, если ушёл хотя бы один канал. */
    public function send(Deal $deal, MessageTemplate $template): bool
    {
        $user = $deal->user;

        if ($user === null) {
            return false;
        }

        $hasTg = ! empty($user->telegram_id);
        $hasVk = ! empty($user->vk_id);
        $hasEmail = (bool) filter_var($user->email, FILTER_VALIDATE_EMAIL);

        if (! $hasTg && ! $hasVk && ! $hasEmail) {
            return false;
        }

        $replacements = MessagePlaceholders::forUser($user, $deal->course);
        $rendered = MessagePlaceholders::render($template->body, $replacements);

        if ($hasTg || $hasVk) {
            SendMessengerAlerts::dispatch($user, $rendered, $hasTg, $hasVk);
        }

        if ($hasEmail) {
            Mail::to($user->email)->queue(new DebtorReminderMail($template->title, $rendered, $user->name));
        }

        return true;
    }
}
