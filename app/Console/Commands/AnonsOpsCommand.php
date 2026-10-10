<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AnonsLinkClick;
use App\Models\AnonsPlacement;
use App\Services\Anons\AnonsArchiveIndexer;
use App\Services\Anons\AnonsMetricsService;
use App\Services\Anons\AnonsOccasionSelector;
use App\Services\Anons\PublicationKey;
use App\Services\Anons\PublicationManifest;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * H5049 R6/R7/R11: anons:metrics | anons:archive-index | anons:archive-search
 * — служебные команды чтения/сбора. Один файл-класс на три узкие операции
 * чтения, чтобы не плодить команды-однодневки.
 */
final class AnonsOpsCommand extends Command
{
    /** Фиксированный словарь каналов (anons-attribution-mint / UTM-стандарт). */
    private const CHANNELS = ['ors', 'mg', 'is', 'it', 'vk', 'samskrte', 'samskrtam'];

    protected $signature = 'anons:ops
        {op : metrics | archive-index | archive-search | history | journal-add | journal-fill | journal-list}
        {--key= : publication_key (metrics/history)}
        {--manifest= : путь манифеста (metrics/history альтернатива key)}
        {--account=rusamskrtam : аккаунт (archive-*)}
        {--limit=100 : потолок архива}
        {--query= : поисковый запрос (archive-search)}
        {--link= : /ga/ ключ (journal-*)}
        {--campaign= : слаг кампании (journal-add — массово, journal-list — фильтр)}
        {--permalink= : ссылка поста (journal-fill)}
        {--published-at= : время публикации, parseable date (journal-fill)}
        {--kind= : вид кампании — обзорное | разовое | обычное (journal-add, H6329)}';

    protected $description = 'Anons operations: metrics, archive indexing/search, placements journal (H5049, H6095)';

    public function handle(AnonsMetricsService $metrics, AnonsArchiveIndexer $archive): int
    {
        $op = (string) $this->argument('op');

        return match ($op) {
            'metrics' => $this->runMetrics($metrics),
            'history' => $this->runHistory($metrics),
            'archive-index' => $this->runArchiveIndex($archive),
            'archive-search' => $this->runArchiveSearch($archive),
            'journal-add' => $this->runJournalAdd(),
            'journal-fill' => $this->runJournalFill(),
            'journal-list' => $this->runJournalList(),
            default => throw new RuntimeException("Unknown op '{$op}' (metrics|history|archive-index|archive-search|journal-add|journal-fill|journal-list)."),
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

    /**
     * H6095: машиной журнал размещений — хвост публикации (permalink/время/
     * 24h/72h клики) командой вместо ручного PR по md-документу. Ключи и UTM
     * выводятся из config/tracked_links.php, не набираются руками.
     */
    private function runJournalAdd(): int
    {
        $link = (string) $this->option('link');
        $campaign = (string) $this->option('campaign');
        if ($link === '' && $campaign === '') {
            $this->error('--link or --campaign required.');

            return self::FAILURE;
        }

        // H6329: вид кампании («по разовым»/«по обзорным»/«по обычным») —
        // fail-closed к словарю канона; пусто = вид не задан (строки до
        // H6329). Повторный journal-add без --kind вид не стирает.
        $kind = trim((string) $this->option('kind'));
        if ($kind !== '' && ! in_array($kind, AnonsOccasionSelector::KINDS, true)) {
            $this->error('Unknown --kind "'.$kind.'"; known kinds: '.implode(', ', AnonsOccasionSelector::KINDS).'.');

            return self::FAILURE;
        }

        $links = (array) config('tracked_links.links', []);
        $keys = $link !== '' ? [$link] : array_values(array_filter(
            array_keys($links),
            fn (string $key): bool => str_starts_with($key, $campaign.'-')
        ));
        if ($keys === []) {
            $this->error("No /ga/ key(s) in config/tracked_links.php for '{$link}{$campaign}' — mint first (anons-attribution-mint).");

            return self::FAILURE;
        }

        foreach ($keys as $key) {
            $parsed = $this->parseLinkKey($key);
            if ($parsed === null) {
                $this->error("Key '{$key}' does not match <campaign>-<channel>-<creative>; story keys (st-) go through story_campaigns.");

                return self::FAILURE;
            }
            $entry = $links[$key] ?? [];
            $attributes = [
                'campaign' => $parsed['campaign'],
                'creative' => $parsed['creative'],
                'channel' => $parsed['channel'],
                'destination' => $entry['destination'] ?? null,
                'utm' => $entry['utm'] ?? null,
            ];
            if ($kind !== '') {
                $attributes['kind'] = $kind;
            }
            $placement = AnonsPlacement::updateOrCreate(
                ['link' => $key],
                $attributes,
            );
            $kindSuffix = $placement->kind !== null ? ' ['.$placement->kind.']' : '';
            $this->line("placement #{$placement->id} {$placement->link} ({$placement->campaign}/{$placement->creative}@{$placement->channel}){$kindSuffix}");
        }

        return self::SUCCESS;
    }

    private function runJournalFill(): int
    {
        $link = (string) $this->option('link');
        if ($link === '') {
            $this->error('--link required.');

            return self::FAILURE;
        }
        $placement = AnonsPlacement::where('link', $link)->first();
        if ($placement === null) {
            $this->error("No placement row for '{$link}' — run anons:ops journal-add --link={$link} first.");

            return self::FAILURE;
        }

        try {
            if ($publishedAt = $this->option('published-at')) {
                $placement->published_at = Carbon::parse((string) $publishedAt);
            }
            $placement->published_at ??= now();
            if ($permalink = $this->option('permalink')) {
                $placement->permalink = (string) $permalink;
            }
            $base = $placement->published_at->copy();
            $clicks = AnonsLinkClick::where('link', $placement->link)
                ->where('clicked_at', '>=', $base);
            $placement->clicks_24h = (clone $clicks)->where('clicked_at', '<=', $base->copy()->addDay())->count();
            $placement->clicks_72h = (clone $clicks)->where('clicked_at', '<=', $base->copy()->addDays(3))->count();
            $placement->save();
        } catch (InvalidFormatException $e) {
            $this->error('Unparseable --published-at value: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'link' => $placement->link,
            'permalink' => $placement->permalink,
            'published_at' => $placement->published_at->toIso8601String(),
            'clicks_24h' => $placement->clicks_24h,
            'clicks_72h' => $placement->clicks_72h,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function runJournalList(): int
    {
        $campaign = (string) $this->option('campaign');
        $rows = AnonsPlacement::query()
            ->when($campaign !== '', fn ($q) => $q->where('campaign', $campaign))
            ->orderBy('link')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No placements recorded.');

            return self::SUCCESS;
        }
        foreach ($rows as $row) {
            $kindSuffix = $row->kind !== null ? ' ['.$row->kind.']' : '';
            $this->line(sprintf(
                '#%d %s %s/%s@%s%s pub=%s 24h=%s 72h=%s %s',
                $row->id, $row->link, $row->campaign, $row->creative, $row->channel,
                $kindSuffix,
                $row->published_at?->format('d-m-Y H:i') ?? '—',
                $row->clicks_24h ?? '—', $row->clicks_72h ?? '—',
                $row->permalink ?? '',
            ));
        }

        return self::SUCCESS;
    }

    /** <кампания>-<канал>-<креатив>; story-ключи (creative начинается с st-) не сюда. */
    private function parseLinkKey(string $key): ?array
    {
        foreach (self::CHANNELS as $channel) {
            $needle = "-{$channel}-";
            $pos = strrpos($key, $needle);
            if ($pos === false) {
                continue;
            }
            $creative = substr($key, $pos + strlen($needle));
            if (str_starts_with($creative, 'st-')) {
                return null;
            }

            return [
                'campaign' => substr($key, 0, $pos),
                'channel' => $channel,
                'creative' => $creative,
            ];
        }

        return null;
    }
}
