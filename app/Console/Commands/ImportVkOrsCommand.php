<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\VkOrsImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copy / validate vk-ors derived CSVs into storage/app/vk_ors (H1564).
 * Does not hit the network or open xlsx.
 */
class ImportVkOrsCommand extends Command
{
    protected $signature = 'content:import-vk-ors
                            {--path= : Directory with activity_by_month.csv etc.}
                            {--force : Overwrite files already in storage/app/vk_ors}';

    protected $description = 'Import vk-ors derived CSVs into storage/app/vk_ors for calendar seeding';

    private const FILES = [
        'activity_by_month.csv',
        'top_posts_by_likes.csv',
        'topics_by_year.csv',
        'hashtags.csv',
        'activity_by_year.csv',
    ];

    public function handle(VkOrsImporter $importer): int
    {
        try {
            $source = VkOrsImporter::resolvePath($this->option('path') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dest = storage_path('app/vk_ors');
        File::ensureDirectoryExists($dest);

        $copied = 0;
        foreach (self::FILES as $file) {
            $from = $source.DIRECTORY_SEPARATOR.$file;
            if (! is_file($from)) {
                $this->warn("skip missing: {$file}");

                continue;
            }
            $to = $dest.DIRECTORY_SEPARATOR.$file;
            if (is_file($to) && ! $this->option('force')) {
                $this->line("exists (use --force): {$file}");

                continue;
            }
            File::copy($from, $to);
            $copied++;
            $this->info("copied {$file}");
        }

        // Sanity: can read activity
        $rows = $importer->readCsv($dest, 'activity_by_month.csv');
        $this->info("import done: {$copied} file(s); activity_by_month rows=".count($rows));

        return self::SUCCESS;
    }
}
