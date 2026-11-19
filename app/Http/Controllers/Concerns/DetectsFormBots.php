<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Анти-бот публичных email-форм (H324): honeypot `website`, time-trap `ff_ts`
 * (зашифрованная метка рендера) и блок-лист доменов. Пороги — config/newsletter.php.
 * Форма должна рендерить оба скрытых поля, иначе любой сабмит считается ботом.
 */
trait DetectsFormBots
{
    /**
     * Похоже ли на бота. Три чисто серверных сигнала (без внешних сервисов):
     *  1) honeypot-поле заполнено (человек его не видит);
     *  2) не прошёл time-trap (мгновенный сабмит или прямой POST без метки);
     *  3) одноразовый/спам-домен из блок-листа.
     */
    private function looksLikeBot(Request $request, string $email): bool
    {
        if (filled($request->input('website'))) {
            return true;
        }

        if (! $this->passedTimeTrap($request->input('ff_ts'))) {
            return true;
        }

        return $this->isBlockedDomain($email);
    }

    /**
     * Проверяет метку времени рендера формы. Отклоняет:
     *  - отсутствующую/битую/чужую метку (прямой POST без рендера формы);
     *  - слишком быстрый сабмит (< min_fill_seconds);
     *  - протухшую метку (> max_form_age_seconds).
     */
    private function passedTimeTrap(mixed $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $renderedAt = (int) decrypt($token);
        } catch (\Throwable) {
            return false; // не наш ключ / повреждена
        }

        $elapsed = now()->timestamp - $renderedAt;

        return $elapsed >= (int) config('newsletter.antibot.min_fill_seconds')
            && $elapsed <= (int) config('newsletter.antibot.max_form_age_seconds');
    }

    private function isBlockedDomain(string $email): bool
    {
        $domain = strtolower(trim((string) substr((string) strrchr($email, '@'), 1)));
        if ($domain === '') {
            return false;
        }

        $blocked = array_map('strtolower', (array) config('newsletter.antibot.blocked_email_domains', []));

        return in_array($domain, $blocked, true);
    }
}
