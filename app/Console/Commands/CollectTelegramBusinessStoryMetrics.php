<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AnonsLinkClick;
use App\Models\TelegramBusinessStoryPublication;
use App\Services\Telegram\MadelineSessionContext;
use App\Services\Telegram\MadelineSyncPhase;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Read final-part reach through the existing isolated MTProto worker. */
final class CollectTelegramBusinessStoryMetrics extends Command
{
    protected $signature = 'telegram-business:story-metrics {--dry-run : Read without storing observations}';

    protected $description = 'Collect per-part Story views and tracked-link clicks for recent Business videos';

    public function handle(): int
    {
        $rows = TelegramBusinessStoryPublication::query()
            ->where('status', 'published')
            ->where(function (Builder $query): void {
                $query->where('started_at', '>=', now()->subDays(3))
                    ->orWhere(fn (Builder $legacy): Builder => $legacy->whereNull('started_at')
                        ->where('created_at', '>=', now()->subDays(3)));
            })
            ->whereNotNull('story_ids')
            ->orderBy('metrics_collected_at')
            ->limit(5)
            ->get();
        if ($rows->isEmpty()) {
            $this->line('No recent published Business Stories.');

            return self::SUCCESS;
        }
        if (MadelineSyncPhase::cooldownActive()) {
            $this->warn('Telegram session is in post-timeout cooldown; metrics remain pending.');

            return self::SUCCESS;
        }

        $ids = $rows->flatMap(fn (TelegramBusinessStoryPublication $row): array => $row->story_ids ?? [])
            ->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)
            ->unique()->values()->all();
        if ($ids === []) {
            return self::SUCCESS;
        }

        try {
            $views = Cache::lock(MadelineSessionContext::lockName(), 300)->block(10, function () use ($ids): array {
                $all = [];
                foreach (array_chunk($ids, 50) as $batch) {
                    $result = Process::timeout(180)->run([
                        PHP_BINARY, base_path('scripts/stories_lane_worker.php'),
                        (string) json_encode(['action' => 'get_story_views', 'story_ids' => $batch]),
                    ]);
                    $payload = null;
                    foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
                        $candidate = json_decode($line, true);
                        if (is_array($candidate) && array_key_exists('ok', $candidate)) {
                            $payload = $candidate;
                        }
                    }
                    if (! $result->successful() || ($payload['ok'] ?? null) !== true || ! is_array($payload['views'] ?? null)) {
                        throw new RuntimeException('Story metrics worker failed: '.mb_substr((string) ($payload['error'] ?? $result->errorOutput()), 0, 250));
                    }
                    $all += $payload['views'];
                }

                return $all;
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($rows as $row) {
            $parts = [];
            $previous = collect($row->metrics['parts'] ?? [])->keyBy('story_id');
            foreach ($row->story_ids ?? [] as $id) {
                $key = (string) $id;
                $value = $views[$key] ?? null;
                if (! is_int($value)) {
                    $old = $previous->get((int) $id);
                    $value = is_array($old) && ($old['state'] ?? null) === 'value'
                        ? ($old['views'] ?? null)
                        : null;
                }
                $parts[] = ['story_id' => (int) $id, 'state' => is_int($value) ? 'value' : 'unavailable', 'views' => $value];
            }
            $last = $parts[array_key_last($parts)] ?? null;
            $clicks = ['state' => 'not_supported', 'value' => null];
            if (preg_match('~^https://samskrte\.ru/ga/([a-z0-9-]+)$~i', (string) $row->cta_url, $match) === 1) {
                $clicks = [
                    'state' => 'value',
                    'value' => AnonsLinkClick::query()->where('link', $match[1])->count(),
                ];
            }
            $metrics = [
                'parts' => $parts,
                'final_part_reach' => ['state' => $last['state'] ?? 'unavailable', 'value' => $last['views'] ?? null],
                'link_clicks' => $clicks,
            ];
            if (! $this->option('dry-run')) {
                $row->update(['metrics' => $metrics, 'metrics_collected_at' => now()]);
            }
            $this->line("Source {$row->source_message_id}: final-part views ".($last['views'] ?? 'unavailable').', tracked clicks '.($clicks['value'] ?? 'n/a'));
        }

        return self::SUCCESS;
    }
}
