<?php

namespace App\Observers;

use App\Support\SearchIndexBuilder;

/**
 * H7590 — после каждого created/updated/deleted в корпусе поиска индекс
 * перегенерируется один раз в конце запроса (terminable-callback, не на
 * каждое событие: админка Filament сохраняет модель пакетами). В юнит-тестах
 * no-op (см. SearchIndexBuilder::write guard).
 */
class SearchIndexObserver
{
    protected static bool $queued = false;

    public function created($model): void
    {
        $this->touch();
    }

    public function updated($model): void
    {
        $this->touch();
    }

    public function deleted($model): void
    {
        $this->touch();
    }

    protected function touch(): void
    {
        if (app()->runningUnitTests() || self::$queued) {
            return;
        }

        self::$queued = true;

        app()->terminating(fn () => app(SearchIndexBuilder::class)->write());
    }
}
