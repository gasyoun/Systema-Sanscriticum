<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MicShadowClassification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * H4608 — недельный дайджест MIC null-телеметрии: uncategorized top-50 +
 * near-miss пары. Продаёт harness-семантику run_corpus.py («Uncategorized
 * top-50 of N», per-plane coverage, per-category n_pred) в прод-контур,
 * где у harness не было доступа.
 *
 * Что НЕ делает: не пишет текст сообщений — в телеметрии его нет по
 * построению (только sha256 + ссылки на channel/conversation/message,
 * по которым человек открывает сообщение в Helpdesk). Никаких решений,
 * никаких флагов: отчёт существует, чтобы правила v2 майнились по фактам
 * (comparative-doc G2/G8), а «тишина классификатора» перестала быть
 * ретроспективной находкой (H3380).
 */
class MicNullDigest extends Command
{
    /** Дублирует harness PRECISION_GATE: порог упомянут в отчёте как контекст. */
    private const PRECISION_GATE = 0.93;

    protected $signature = 'support:mic-null-digest
        {--days=7 : Окно телеметрии в днях}
        {--top=50 : Сколько uncategorized строк показывать (harness top-50)}
        {--write= : Записать отчёт в файл (по умолчанию storage/app/reports/mic-null-digest/<дата>.md)}
        {--no-write : Только показать сводку в консоли, файл не создавать}';

    protected $description = 'H4608: недельный дайджест MIC shadow-телеметрии — uncategorized top-50 + near-miss пары. Только чтение.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $top = max(1, (int) $this->option('top'));
        $since = now()->subDays($days);

        $rows = MicShadowClassification::query()
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("mic_shadow_classifications: no rows in the last {$days} day(s) — nothing to digest (flag off or no traffic).");

            return self::SUCCESS;
        }

        $report = $this->renderReport($rows, $days, $top);
        $uncategorized = $this->uncategorizedTopicRows($rows);

        if (! (bool) $this->option('no-write')) {
            $path = (string) ($this->option('write')
                ?: storage_path('app/reports/mic-null-digest/'.now()->format('Y-m-d').'.md'));
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $report);
            $this->info("report written: {$path}");
        }

        $topic = $rows->where('plane', 'topic');
        $this->info(sprintf(
            'window=%dd rows=%d topic=%d topic-null=%d (%.1f%%) uncategorized-listed=%d',
            $days,
            $rows->count(),
            $topic->count(),
            $topic->whereNull('category')->count(),
            $topic->count() > 0 ? 100.0 * $topic->whereNull('category')->count() / $topic->count() : 0.0,
            min($top, $uncategorized->count()),
        ));

        return self::SUCCESS;
    }

    private function renderReport($rows, int $days, int $top): string
    {
        $lines = [
            '# MIC null digest — shadow classify-all-inbound',
            '',
            sprintf('_Generated: %s · window: last %d day(s) · H4608 log-only telemetry_', now()->toDateString(), $days),
            '',
            sprintf(
                'Context gate: classifier runtime flip stays blocked until corpus precision >= %.2f (H3529). This report feeds rules-v2 mining only.',
                self::PRECISION_GATE,
            ),
            '',
            '## Per-plane coverage',
            '',
            '| plane | rows | categorized | null | coverage |',
            '|---|---|---|---|---|',
        ];

        foreach (['topic', 'objection', 'intent', 'meta'] as $plane) {
            $planeRows = $rows->where('plane', $plane);
            $nulls = $planeRows->whereNull('category')->count();
            $categorized = $planeRows->count() - $nulls;
            $coverage = $planeRows->count() > 0 ? $categorized / $planeRows->count() : 0.0;
            $lines[] = sprintf('| %s | %d | %d | %d | %.1f%% |', $plane, $planeRows->count(), $categorized, $nulls, $coverage * 100.0);
        }

        $lines[] = '';
        $lines[] = '## Per-category volume (n_pred)';
        $lines[] = '';

        foreach (['topic', 'objection', 'intent', 'meta'] as $plane) {
            $counts = $rows->where('plane', $plane)->whereNotNull('category')->groupBy('category')->map->count()->sortDesc();
            if ($counts->isEmpty()) {
                continue;
            }
            $lines[] = "### {$plane}";
            $lines[] = '';
            $lines[] = '| category | n_pred |';
            $lines[] = '|---|---|';
            foreach ($counts as $category => $n) {
                $lines[] = sprintf('| %s | %d |', $category, $n);
            }
            $lines[] = '';
        }

        // G8: near-miss пары по uncategorized строкам topic-плоскости —
        // кандидаты для новых/ослабленных правил следующей итерации.
        $uncategorized = $this->uncategorizedTopicRows($rows);
        $nearMissCounts = [];
        foreach ($uncategorized as $row) {
            foreach ((array) ($row->near_miss ?? []) as $pair) {
                $key = (($pair['category'] ?? '?')).' ('.(($pair['reason'] ?? '?')).')';
                $nearMissCounts[$key] = ($nearMissCounts[$key] ?? 0) + 1;
            }
        }
        arsort($nearMissCounts);

        $lines[] = '## Near-miss pairs on uncategorized topic rows';
        $lines[] = '';
        if ($nearMissCounts === []) {
            $lines[] = '_(none — rules are not even close, or near-miss absent by construction)_';
            $lines[] = '';
        } else {
            $lines[] = '| near-miss (category + reason) | count |';
            $lines[] = '|---|---|';
            foreach (array_slice($nearMissCounts, 0, 10, true) as $pair => $count) {
                $lines[] = sprintf('| %s | %d |', $pair, $count);
            }
            $lines[] = '';
        }

        $sample = $uncategorized->take($top);
        $lines[] = sprintf('### Uncategorized top-%d of %d', min($top, $uncategorized->count()), $uncategorized->count());
        $lines[] = '';
        if ($sample->isEmpty()) {
            $lines[] = '_(no uncategorized topic rows in the window — healthy coverage or empty telemetry)_';
        }
        foreach ($sample as $row) {
            $near = collect((array) ($row->near_miss ?? []))
                ->map(fn (array $p) => sprintf('%s (%s)', $p['category'] ?? '?', $p['reason'] ?? '?'))
                ->implode(' | ');
            $lines[] = sprintf(
                '- `%s#%d` %s conv=%s msg=%s %s hash=%s near-miss: %s',
                $row->channel,
                $row->id,
                optional($row->created_at)->toDateTimeString(),
                $row->conversation_id ?? '-',
                $row->message_id ?? '-',
                $row->classified_at?->format('Y-m-d') ?? '-',
                substr((string) $row->text_hash, 0, 12),
                $near !== '' ? $near : '—',
            );
        }
        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function uncategorizedTopicRows($rows)
    {
        return $rows->where('plane', 'topic')->whereNull('category')->values();
    }
}
