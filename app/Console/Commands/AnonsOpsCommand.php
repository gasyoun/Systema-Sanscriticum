<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Anons\AnonsArchiveIndexer;
use App\Services\Anons\AnonsMetricsService;
use App\Services\Anons\PublicationKey;
use App\Services\Anons\PublicationManifest;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * H5049 R6/R7/R11: anons:metrics | anons:archive-index | anons:archive-search
 * — служебные команды чтения/сбора. Один файл-класс на три узкие операции
 * чтения, чтобы не плодить команды-однодневки.
 */
final class AnonsOpsCommand extends Command
{
    protected $signature = 'anons:ops
        {op : metrics | archive-index | archive-search | history}
        {--key= : publication_key (metrics/history)}
        {--manifest= : путь манифеста (metrics/history альтернатива key)}
        {--account=rusamskrtam : аккаунт (archive-*)}
        {--limit=100 : потолок архива}
        {--query= : поисковый запрос (archive-search)}';

    protected $description = 'Anons operations: metrics collection, archive indexing/search (H5049)';

    public function handle(AnonsMetricsService $metrics, AnonsArchiveIndexer $archive): int
    {
        $op = (string) $this->argument('op');

        return match ($op) {
            'metrics' => $this->runMetrics($metrics),
            'history' => $this->runHistory($metrics),
            'archive-index' => $this->runArchiveIndex($archive),
            'archive-search' => $this->runArchiveSearch($archive),
            default => throw new RuntimeException("Unknown op '{$op}' (metrics|history|archive-index|archive-search)."),
        };
    }

    private function runMetrics(AnonsMetricsService $metrics): int
    {
        $key = $this->resolveKey();
        if ($key === null) {
            $this->error('--key or --manifest required.');

            return self::FAILURE;
        }

        $readout = $metrics->collect($key);
        $this->line(json_encode($readout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function runHistory(AnonsMetricsService $metrics): int
    {
        $key = $this->resolveKey();
        if ($key === null) {
            $this->error('--key or --manifest required.');

            return self::FAILURE;
        }
        $this->line(json_encode($metrics->history($key), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function runArchiveIndex(AnonsArchiveIndexer $archive): int
    {
        $result = $archive->indexAccount((string) $this->option('account'), (int) $this->option('limit'));
        $this->info("Indexed {$result['indexed']} archive item(s), downloaded {$result['downloaded']} media file(s).");

        return self::SUCCESS;
    }

    private function runArchiveSearch(AnonsArchiveIndexer $archive): int
    {
        $items = $archive->search((string) $this->option('query'), (string) $this->option('account'));
        if ($items === []) {
            $this->info('No archive items match.');

            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $this->line(sprintf(
                '#%d %s@%s [%s] %s %s %s',
                $item->id, $item->platform, $item->account,
                $item->captured_at?->format('d-m-Y'), $item->remote_id,
                $item->media_hash !== null ? substr($item->media_hash, 0, 12) : 'no-hash',
                mb_substr((string) $item->text, 0, 60),
            ));
        }

        return self::SUCCESS;
    }

    private function resolveKey(): ?string
    {
        if ($key = $this->option('key')) {
            return (string) $key;
        }
        if ($manifest = $this->option('manifest')) {
            return PublicationKey::fromManifest(
                PublicationManifest::fromFile((string) $manifest)
            );
        }

        return null;
    }
}
