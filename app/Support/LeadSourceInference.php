<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Lead;

/**
 * H5021 — вывод источника лида из того, что уже сохранено в строке
 * (UTM → статья → referrer → кабинет → канал лид-магнита). Чистая функция:
 * ничего не пишет, возвращает [source, rule] либо null, если вывести нечего.
 *
 * Порядок правил = убывающая достоверность. Значение source — короткий ключ
 * канала (vk, telegram, yandex, google, article, site, cabinet, …), чтобы
 * report:channel-roi группировал по нему как по utm_source.
 *
 * Правило magnet_channel — самое слабое: канал ДОСТАВКИ лид-магнита, а не
 * прихода; помечено отдельным именем, чтобы отчёт показывал его долю и человек
 * мог его выключить. Список правил и их веса — отчёт H5021 в Uprava/reports.
 */
final class LeadSourceInference
{
    public const RULE_UTM_SOURCE = 'utm_source';

    public const RULE_SOURCE_ARTICLE = 'source_article';

    public const RULE_REFERRER_HOST = 'referrer_host';

    public const RULE_LOGGED_IN_USER = 'logged_in_user';

    public const RULE_MAGNET_CHANNEL = 'magnet_channel';

    /** @var array<string,string> алиасы utm_source / хостов → ключ канала */
    private const ALIASES = [
        'vk' => 'vk', 'vk.com' => 'vk', 'm.vk.com' => 'vk', 'vk.ru' => 'vk', 'vkontakte' => 'vk',
        'telegram' => 'telegram', 'tg' => 'telegram', 't.me' => 'telegram', 'telegram.me' => 'telegram',
        'telegram.org' => 'telegram', 'web.telegram.org' => 'telegram', 'tgstat.ru' => 'telegram',
        'yandex' => 'yandex', 'ya' => 'yandex', 'ya.ru' => 'yandex', 'yandex.ru' => 'yandex',
        'yandex.com' => 'yandex', 'yandex.kz' => 'yandex', 'yandex.by' => 'yandex', 'yabs.yandex.ru' => 'yandex',
        'dzen' => 'dzen', 'dzen.ru' => 'dzen', 'zen.yandex.ru' => 'dzen',
        'google' => 'google', 'google.com' => 'google', 'google.ru' => 'google',
        'youtube' => 'youtube', 'yt' => 'youtube', 'youtube.com' => 'youtube', 'm.youtube.com' => 'youtube', 'youtu.be' => 'youtube',
        'instagram' => 'instagram', 'ig' => 'instagram', 'instagram.com' => 'instagram', 'l.instagram.com' => 'instagram',
        'facebook' => 'facebook', 'fb' => 'facebook', 'facebook.com' => 'facebook', 'm.facebook.com' => 'facebook', 'l.facebook.com' => 'facebook',
        'ok' => 'ok', 'ok.ru' => 'ok', 'odnoklassniki' => 'ok',
        'bing' => 'bing', 'bing.com' => 'bing',
        'duckduckgo' => 'duckduckgo', 'duckduckgo.com' => 'duckduckgo',
        'mail.ru' => 'mail_ru', 'go.mail.ru' => 'mail_ru', 'e.mail.ru' => 'mail_ru',
        'whatsapp' => 'whatsapp', 'wa' => 'whatsapp',
        'max' => 'max',
    ];

    /** Собственные домены: переход с них — внутренняя навигация, не внешний канал. */
    private const OWN_HOSTS = ['samskrte.ru', 'samskrtam.ru'];

    /**
     * @return array{source:string, rule:string}|null
     */
    public static function infer(Lead $lead): ?array
    {
        $utm = self::normalize((string) $lead->utm_source);
        if ($utm !== null) {
            return ['source' => $utm, 'rule' => self::RULE_UTM_SOURCE];
        }

        if (trim((string) $lead->source_article_slug) !== '') {
            return ['source' => 'article', 'rule' => self::RULE_SOURCE_ARTICLE];
        }

        $host = self::referrerHost((string) $lead->referrer);
        if ($host !== null) {
            return ['source' => self::channelForHost($host), 'rule' => self::RULE_REFERRER_HOST];
        }

        if ($lead->user_id !== null) {
            return ['source' => 'cabinet', 'rule' => self::RULE_LOGGED_IN_USER];
        }

        $magnet = strtolower(trim((string) $lead->magnet_channel));
        if ($magnet !== '') {
            return ['source' => 'magnet_'.$magnet, 'rule' => self::RULE_MAGNET_CHANNEL];
        }

        return null;
    }

    /** Ключ канала из utm_source: алиасы схлопываются, остальное — slug ≤64 (латиница/цифры). */
    public static function normalize(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }
        $value = preg_replace('~^https?://~', '', $value) ?? $value;
        $value = preg_replace('~^www\.~', '', $value) ?? $value;
        if (isset(self::ALIASES[$value])) {
            return self::ALIASES[$value];
        }
        $slug = preg_replace('~[^a-z0-9._-]+~', '_', $value) ?? '';
        $slug = trim($slug, '_');

        return $slug === '' ? null : mb_substr($slug, 0, 64);
    }

    public static function referrerHost(string $referrer): ?string
    {
        $referrer = trim($referrer);
        if ($referrer === '') {
            return null;
        }
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $referrer)) {
            $referrer = 'https://'.$referrer;
        }
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        $host = preg_replace('~^www\.~', '', $host) ?? $host;

        return $host === '' ? null : $host;
    }

    public static function channelForHost(string $host): string
    {
        if (in_array($host, self::OWN_HOSTS, true)) {
            return 'site';
        }
        foreach (self::OWN_HOSTS as $own) {
            if (str_ends_with($host, '.'.$own)) {
                return 'site';
            }
        }
        if (isset(self::ALIASES[$host])) {
            return self::ALIASES[$host];
        }
        // Поддомены известных сетей (away.vk.com, l.facebook.com, …).
        foreach (self::ALIASES as $alias => $channel) {
            if (str_contains($alias, '.') && str_ends_with($host, '.'.$alias)) {
                return $channel;
            }
        }

        return self::normalize($host) ?? 'unknown';
    }
}
