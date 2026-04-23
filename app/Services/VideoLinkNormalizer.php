<?php

declare(strict_types=1);

namespace App\Services;

final class VideoLinkNormalizer
{
    /**
     * Нормализует ссылку YouTube к виду https://youtu.be/{ID}.
     * Возвращает null, если ID извлечь не удалось.
     */
    public function youtube(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // Полные ссылки: youtube.com/watch?v=, youtu.be/, /embed/, /v/, /e/
        if (preg_match(
            '#(?:youtube\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([A-Za-z0-9_-]{11})#i',
            $raw,
            $m
        )) {
            return 'https://youtu.be/' . $m[1];
        }

        // Вдруг в БД лежит голый ID (11 символов)
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $raw)) {
            return 'https://youtu.be/' . $raw;
        }

        return null;
    }

    /**
 * Нормализует ссылку Rutube.
 * Приватное видео (определяется по наличию токена ?p=):
 *   https://rutube.ru/video/private/{HASH}/?p={TOKEN}
 * Публичное:
 *   https://rutube.ru/video/{HASH}/
 *
 * На вход принимаем любые варианты:
 *   - https://rutube.ru/video/{hash}/
 *   - https://rutube.ru/video/private/{hash}/?p={token}
 *   - https://rutube.ru/play/embed/{hash}/?p={token}   ← embed приватного
 *   - https://rutube.ru/play/embed/{hash}/             ← embed публичного
 *   - голый {hash} (32 hex)
 */
public function rutube(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $hash  = null;
    $token = null;

    // 1. Вытаскиваем hash из любого типа URL
    if (preg_match(
        '#rutube\.ru/(?:video/private/|video/|play/embed/private/|play/embed/)([a-f0-9]{32})#i',
        $raw,
        $m
    )) {
        $hash = strtolower($m[1]);
    } elseif (preg_match('/^[a-f0-9]{32}$/i', $raw)) {
        // Голый hash в БД
        $hash = strtolower($raw);
    } else {
        return null;
    }

    // 2. Достаём токен p=... из query-строки, если он есть
    $query = parse_url($raw, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $params);
        if (!empty($params['p'])) {
            $token = (string) $params['p'];
        }
    }

    // 3. Признак приватности — наличие токена.
    //    Путь URL (/video/private/ vs /play/embed/) для определения приватности
    //    ненадёжен: embed-ссылки приватных видео идут через /play/embed/{hash}/?p=...
    if ($token !== null) {
        return sprintf('https://rutube.ru/video/private/%s/?p=%s', $hash, $token);
    }

    return sprintf('https://rutube.ru/video/%s/', $hash);
}
}