<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AnonsPublication;
use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\PublicationManifest;
use Illuminate\Console\Command;

/**
 * H5049 R2/R8/R10/R14: anons:publish {manifest} [--promote]
 *
 * Идемпотентная публикация: повторный прогон того же манифеста резюмирует
 * или отчитывает существующую публикацию, дубль невозможен. --promote
 * переводит тест-публикацию в прод (манифест должен быть уже с
 * test_mode=false; артефакт переиспользуется по content-hash).
 */
final class AnonsPublishCommand extends Command
{
    protected $signature = 'anons:publish
        {manifest : Путь к YAML/JSON манифесту}
        {--promote : Промоция принятой тест-публикации в прод}
        {--report-key= : Не публиковать; отчитать статус по publication_key}';

    protected $description = 'Idempotent anons publication from a declarative manifest (H5049)';

    public function handle(AnonsPublishingService $service): int
    {
        if ($reportKey = $this->option('report-key')) {
            $status = $service->status((string) $reportKey);
            if ($status === null) {
                $this->error("Unknown publication key: {$reportKey}");

                return self::FAILURE;
            }
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $manifest = PublicationManifest::fromFile((string) $this->argument('manifest'));

        $publication = $service->publish($manifest, promote: (bool) $this->option('promote'));

        $this->info('publication_key: '.$publication->publication_key);
        $this->info('status: '.$publication->status.($publication->test_mode ? ' (TEST MODE)' : ''));

        foreach ($publication->runs()->get() as $run) {
            $remote = $run->remote_ids[$run->frame_index] ?? null;
            $this->line(sprintf(
                '  [%s] frame %d %s → %s%s',
                $run->state,
                $run->frame_index,
                $run->destination,
                $remote !== null ? "remote_id={$remote}" : '-',
                $run->last_error !== null ? '  error: '.mb_substr($run->last_error, 0, 160) : ''
            ));
        }

        return in_array($publication->status, [AnonsPublication::STATUS_PUBLISHED, AnonsPublication::STATUS_PARTIAL], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
