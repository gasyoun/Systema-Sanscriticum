<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Сбрасывает кэш sitemap.xml при изменении контента, который в него попадает.
 * Без этого Google и Яндекс до часа видят устаревший sitemap после
 * публикации новой статьи/курса/лендинга.
 *
 * Подключается к LandingPage / Course / Article через model::observe().
 *
 * Слушаем `created/updated/deleted`, а не `saved` — последний срабатывает
 * на noop $model->save() (Filament делает так при каждом Save в форме,
 * даже без isDirty), что приводит к лишним Cache::forget.
 */
final class SitemapCacheInvalidator
{
    public function created(Model $model): void
    {
        $this->flush();
    }

    public function updated(Model $model): void
    {
        $this->flush();
    }

    public function deleted(Model $model): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        Cache::forget('sitemap.xml');
    }
}
