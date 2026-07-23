<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Парсер ссылок на видео в embed-URL и постеры.
 * Используется в карусели «Открытые занятия» на главной и в блоке cta_banner_block.
 * Поддерживает YouTube, RuTube, VK Video, Vimeo, Kinescope (H1451).
 *
 * Kinescope-пилот в плеере урока scoped отдельно: flag + course id
 * (см. KinescopePilot / config/video.php). Сам парсер URL всегда
 * распознаёт Kinescope-ссылки.
 */
final class VideoEmbed
{
    /**
     * Превращает «человеческую» ссылку в URL для iframe-плеера.
     * Возвращает null, если ссылка не распознана.
     */
    public static function embed(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if ($id = self::youtubeId($url)) {
            return "https://www.youtube.com/embed/{$id}?autoplay=1";
        }

        if ($id = self::rutubeId($url)) {
            // Сохраняем токен доступа ?p=... — без него непубличные ролики
            // вернут 403 в iframe.
            $access = '';
            if (preg_match('/[?&]p=([A-Za-z0-9_-]+)/i', $url, $pm)) {
                $access = '?p='.$pm[1];
            }

            return "https://rutube.ru/play/embed/{$id}{$access}";
        }

        if ($vk = self::vkVideoIds($url)) {
            [$oid, $vid] = $vk;

            return "https://vk.com/video_ext.php?oid={$oid}&id={$vid}&hd=2&autoplay=1";
        }

        if ($id = self::vimeoId($url)) {
            return "https://player.vimeo.com/video/{$id}?autoplay=1";
        }

        if ($id = self::kinescopeId($url)) {
            return "https://kinescope.io/embed/{$id}";
        }

        return null;
    }

    /** True when the URL is a recognised Kinescope watch/embed link. */
    public static function isKinescope(?string $url): bool
    {
        return self::kinescopeId($url) !== null;
    }

    /**
     * Постер для превью карточки. Возвращает URL картинки или null —
     * шаблон сам подставит fallback (обложку курса / placeholder).
     */
    public static function poster(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if ($id = self::youtubeId($url)) {
            return "https://img.youtube.com/vi/{$id}/hqdefault.jpg";
        }

        // Для RuTube / VK / Vimeo постер достать без API-запроса нельзя.
        // Возвращаем null — карусель покажет fallback.
        return null;
    }

    private static function youtubeId(string $url): ?string
    {
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function rutubeId(string $url): ?string
    {
        // Поддерживаем форматы ссылок:
        // - https://rutube.ru/video/{id}/
        // - https://rutube.ru/shorts/{id}/
        // - https://rutube.ru/play/embed/{id}/?p=...
        if (preg_match('/rutube\.ru\/(?:video|shorts|play\/embed)\/([a-zA-Z0-9_-]+)/i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return array{0:string,1:string}|null */
    private static function vkVideoIds(string $url): ?array
    {
        if (preg_match('/vk\.com\/(?:clip|video)(-?\d+)_(\d+)/i', $url, $m)) {
            return [$m[1], $m[2]];
        }

        return null;
    }

    private static function vimeoId(string $url): ?string
    {
        if (preg_match('/vimeo\.com\/(\d+)/i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Kinescope video id from common URL shapes:
     * - https://kinescope.io/{id}
     * - https://kinescope.io/embed/{id}
     * - https://kinescope.io/video/{id}
     */
    public static function kinescopeId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (preg_match('/kinescope\.io\/(?:embed\/|video\/)?([A-Za-z0-9_-]{6,})/i', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}
