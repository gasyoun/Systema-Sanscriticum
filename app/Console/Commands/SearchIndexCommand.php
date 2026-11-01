<?php

namespace App\Console\Commands;

use App\Support\SearchIndexBuilder;
use Illuminate\Console\Command;

/**
 * H7590 — (пере)генерация публичного JSON-индекса глобального поиска.
 *
 * Запускается: вручную, при деплое (deploy.sh после migrate) и дебаунс-ом
 * наблюдателя SearchIndexObserver после каждого сохранения Course/Article/
 * Teacher/LandingPage. Выход: public/search/index-{hash}.json + manifest.json.
 */
class SearchIndexCommand extends Command
{
    protected $signature = 'search:index';

    protected $description = 'Regenerate the public client-side search index (public/search)';

    public function handle(SearchIndexBuilder $builder): int
    {
        $result = $builder->write();

        if (! empty($result['skipped'])) {
            $this->components->info('search:index skipped (unit-test environment).');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Search index: %d items → search/%s (hash %s)',
            $result['count'],
            $result['file'],
            $result['hash'],
        ));

        return self::SUCCESS;
    }
}
