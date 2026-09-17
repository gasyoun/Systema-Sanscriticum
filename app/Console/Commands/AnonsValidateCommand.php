<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\PublicationKey;
use App\Services\Anons\PublicationManifest;
use Illuminate\Console\Command;

/**
 * H5049 R1/R10: anons:validate {manifest}
 * Схемная + адаптерная проверка манифеста до любой публикации.
 */
final class AnonsValidateCommand extends Command
{
    protected $signature = 'anons:validate {manifest : Путь к YAML/JSON манифесту}';

    protected $description = 'Validate an anons publication manifest (fail-closed, H5049)';

    public function handle(AnonsPublishingService $service): int
    {
        $manifest = PublicationManifest::fromFile((string) $this->argument('manifest'));
        $errors = $service->validate($manifest);

        if ($errors === []) {
            $this->info('Manifest OK (fail-closed validator passed, H5049).');
            $this->info('publication_key: '.PublicationKey::fromManifest($manifest));
            $this->info('manifest_hash: '.$manifest->hash());

            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $this->error('✗ '.$error);
        }

        return self::FAILURE;
    }
}
