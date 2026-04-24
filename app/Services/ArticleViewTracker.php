<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ArticleViewTracker
{
    /**
     * Окно дедупликации — сколько минут один и тот же посетитель
     * не учитывается повторно для той же статьи.
     */
    private const DEDUP_WINDOW_MINUTES = 30;

    /**
     * Паттерны для детекта ботов в User-Agent.
     * Не считаем их просмотры, чтобы статистика отражала реальных людей.
     */
    private const BOT_PATTERNS = [
        'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
        'python', 'java/', 'yandex', 'googlebot', 'bingbot',
        'duckduck', 'facebookexternalhit', 'twitterbot',
        'telegrambot', 'whatsapp', 'slackbot', 'vkshare',
        'headlesschrome', 'puppeteer', 'playwright', 'phantomjs',
    ];

    /**
     * Записать просмотр статьи, если это реальный посетитель и не дубль.
     *
     * @return bool true, если просмотр записан
     */
    public function track(Article $article, Request $request): bool
    {
        // 1. Админов — не считаем (свои ходят по сайту часто, скажут статистику)
        if ($this->isAdmin($request)) {
            return false;
        }

        // 2. Ботов — не считаем
        $userAgent = $request->userAgent() ?? '';
        if ($this->isBot($userAgent)) {
            return false;
        }

        // 3. Строим стабильный хэш посетителя
        $visitorHash = $this->buildVisitorHash($request);

        // 4. Дедупликация: если этот visitor уже смотрел эту статью в окне — пропускаем
        $dedupKey = "article_view:{$article->id}:{$visitorHash}";
        if (Cache::has($dedupKey)) {
            return false;
        }

        // 5. Пишем в БД (Observer сам инкрементит views_count)
        ArticleView::create([
            'article_id'   => $article->id,
            'visitor_hash' => $visitorHash,
            'ip'           => $request->ip(),
            'referrer'     => $this->truncate($request->headers->get('referer'), 500),
            'user_agent'   => $this->truncate($userAgent, 500),
        ]);

        // 6. Ставим дедуп-метку в кеш
        Cache::put($dedupKey, 1, now()->addMinutes(self::DEDUP_WINDOW_MINUTES));

        return true;
    }

    /**
     * Авторизованный админ — не считаем его просмотры.
     */
    private function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && ($user->is_admin ?? false);
    }

    /**
     * Простая эвристика по User-Agent. Не ловит всех, но отсекает 95% шума.
     */
    private function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return true; // запрос без UA — почти всегда бот/скрипт
        }

        $ua = strtolower($userAgent);

        foreach (self::BOT_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Уникальный хэш посетителя.
     * IP + UA + ежедневная соль из APP_KEY.
     * => один человек = один хэш в течение суток.
     */
    private function buildVisitorHash(Request $request): string
    {
        $dailySalt = hash('sha256', config('app.key') . now()->toDateString());

        return hash('sha256', implode('|', [
            $request->ip() ?? 'unknown',
            $request->userAgent() ?? 'unknown',
            $dailySalt,
        ]));
    }

    private function truncate(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}