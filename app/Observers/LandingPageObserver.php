<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LandingPage;
use Illuminate\Support\Facades\Cache;

class LandingPageObserver
{
    /**
     * Ключ кэша формируется в PromoController::show как "promo_page_{slug}".
     * Держим формат в одном месте, чтобы при рефакторинге не разъехалось.
     */
    private const CACHE_KEY_PREFIX = 'promo_page_';

    /**
     * После создания — чистим кэш на будущий slug
     * (на случай, если он попал в кэш как 404 через firstOrFail).
     */
    public function created(LandingPage $page): void
    {
        $this->forgetCache($page->slug);
    }

    /**
     * После сохранения (update) — чистим и текущий, и старый slug,
     * если slug менялся. Иначе старая страница останется в кэше
     * под старым URL и будет отдавать устаревший контент ещё сутки.
     */
    public function updated(LandingPage $page): void
    {
        $this->forgetCache($page->slug);

        if ($page->wasChanged('slug')) {
            $this->forgetCache($page->getOriginal('slug'));
        }
    }

    /**
     * После удаления — чистим кэш, чтобы мёртвая страница
     * не продолжала отдаваться из Redis.
     */
    public function deleted(LandingPage $page): void
    {
        $this->forgetCache($page->slug);
    }

    /**
     * При восстановлении (если когда-нибудь прикрутишь SoftDeletes) — тоже чистим.
     */
    public function restored(LandingPage $page): void
    {
        $this->forgetCache($page->slug);
    }

    private function forgetCache(?string $slug): void
    {
        if (empty($slug)) {
            return;
        }

        Cache::forget(self::CACHE_KEY_PREFIX . $slug);
    }
}