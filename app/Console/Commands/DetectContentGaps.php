<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentGapSuggestion;
use App\Models\MessageTemplate;
use App\Models\SupportTopicAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * CAI3 (docs/ROADMAP_CONTENT_AI_2026_2027.md): rank support categories by
 * curator-time-saved potential (same "deflection" metric as
 * `support:topic-ranking`) and flag which ones have NO existing support
 * MessageTemplate — the content gaps worth drafting an FAQ/social post for
 * (CAI4/CAI5/CAI6 pick this up). Persists one ContentGapSuggestion row per
 * (category, window); re-running the same window updates in place.
 *
 * H1837 — like `support:topic-ranking`, this now reads BOTH channels: the join
 * is on `support_daily_rollups`, which since that change carries web-chat rows
 * alongside Telegram ones. Deliberately unfiltered by channel — a content gap is
 * a gap wherever the question arrives, and a topic asked only through the site
 * widget was previously invisible here.
 *
 * `has_content` is a title/body substring heuristic against active
 * MessageTemplate::CATEGORY_SUPPORT templates — there is no structured join
 * key between free-text SupportTopicRule categories and MessageTemplate, so
 * this is approximate by design, not a guarantee of coverage.
 */
class DetectContentGaps extends Command
{
    protected $signature = 'content:detect-gaps
        {--months=6 : Look-back window in months (ignored when --since is given)}
        {--since= : Lower bound date YYYY-MM-DD (overrides --months)}
        {--until= : Upper bound date YYYY-MM-DD (defaults to today)}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Detect support-content gaps: high-deflection categories with no matching FAQ/support template (CAI3).';

    public function handle(): int
    {
        $until = $this->option('until')
            ? CarbonImmutable::parse($this->option('until'))
            : CarbonImmutable::now();
        $since = $this->option('since')
            ? CarbonImmutable::parse($this->option('since'))
            : $until->subMonths((int) $this->option('months'));

        [$rows, $mode] = $this->rankWeighted($since, $until);

        if ($rows->isEmpty()) {
            [$rows, $mode] = $this->rankVolumeOnly($since, $until);
        }

        $templateTexts = MessageTemplate::query()
            ->forCategory(MessageTemplate::CATEGORY_SUPPORT)
            ->get(['title', 'body'])
            ->map(fn (MessageTemplate $t): string => mb_strtolower($t->title.' '.$t->body))
            ->all();

        $suggestions = $rows
            // "uncategorized" is a classifier miss, not a real content topic —
            // never worth a gap suggestion (nothing to write content about).
            ->reject(fn ($r) => ($r->category ?: 'uncategorized') === 'uncategorized')
            ->map(function ($r) use ($templateTexts, $since, $until): array {
                $queries = (int) $r->queries;
                $ai = (int) $r->ai_sent;
                $human = (int) $r->human_replies;
                $category = $r->category;
                $deflection = $human > 0 ? $human : (int) $r->chat_days;
                $hasContent = $this->hasMatchingTemplate($category, $templateTexts);

                $attrs = [
                    'category' => $category,
                    'window_since' => $since->toDateString(),
                    'window_until' => $until->toDateString(),
                    'chat_days' => (int) $r->chat_days,
                    'queries' => $queries,
                    'human_replies' => $human,
                    'ai_sent' => $ai,
                    'unanswered' => (int) $r->unanswered,
                    'curator_minutes' => (int) round(((int) $r->curator_seconds) / 60),
                    'auto_rate' => ($ai + $human) > 0 ? round($ai / ($ai + $human), 3) : null,
                    'deflection_score' => $deflection,
                    'has_content' => $hasContent,
                ];

                ContentGapSuggestion::updateOrCreate([
                    'category' => $attrs['category'],
                    'window_since' => $attrs['window_since'],
                    'window_until' => $attrs['window_until'],
                ], $attrs);

                return $attrs;
            })->sortByDesc('deflection_score')->values();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'window' => ['since' => $since->toDateString(), 'until' => $until->toDateString()],
                'mode' => $mode,
                'rows' => $suggestions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Content gaps — %s → %s  (%s)',
            $since->toDateString(),
            $until->toDateString(),
            $mode,
        ));

        if ($suggestions->isEmpty()) {
            $this->warn('No classified support activity in this window.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'category', 'chat-days', 'queries', 'human replies', 'deflection', 'auto %', 'has content?'],
            $suggestions->map(fn (array $r, int $i): array => [
                $i + 1,
                $r['category'],
                $r['chat_days'],
                $r['queries'] ?: '—',
                $r['human_replies'] ?: '—',
                $r['deflection_score'],
                $r['auto_rate'] === null ? '—' : (int) round($r['auto_rate'] * 100).'%',
                $r['has_content'] ? 'yes' : 'GAP',
            ])->all(),
        );

        $gapCount = $suggestions->where('has_content', false)->where('deflection_score', '>', 0)->count();
        $this->newLine();
        $this->line("{$gapCount} categor".($gapCount === 1 ? 'y' : 'ies').' flagged GAP (deflection > 0, no matching template) — persisted to content_gap_suggestions.');

        return self::SUCCESS;
    }

    /** @return array{0: Collection, 1: string} */
    private function rankWeighted(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $rows = SupportTopicAssignment::query()
            ->join('support_daily_rollups as r', 'r.id', '=', 'support_topic_assignments.support_daily_rollup_id')
            ->whereBetween('r.conversation_date', [$since->toDateString(), $until->toDateString()])
            ->groupBy('support_topic_assignments.category')
            ->selectRaw('support_topic_assignments.category as category')
            ->selectRaw('count(*) as chat_days')
            ->selectRaw('coalesce(sum(r.incoming_count),0) as queries')
            ->selectRaw('coalesce(sum(r.human_reply_count),0) as human_replies')
            ->selectRaw('coalesce(sum(r.ai_sent_count),0) as ai_sent')
            ->selectRaw('coalesce(sum(r.is_unanswered),0) as unanswered')
            ->selectRaw('coalesce(sum(r.first_response_seconds),0) as curator_seconds')
            ->get();

        return [$rows, 'rollup-weighted'];
    }

    /** @return array{0: Collection, 1: string} */
    private function rankVolumeOnly(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $rows = SupportTopicAssignment::query()
            ->whereBetween('created_at', [$since, $until])
            ->groupBy('category')
            ->selectRaw('category')
            ->selectRaw('count(*) as chat_days')
            ->selectRaw('0 as queries')
            ->selectRaw('0 as human_replies')
            ->selectRaw('0 as ai_sent')
            ->selectRaw('0 as unanswered')
            ->selectRaw('0 as curator_seconds')
            ->get();

        return [$rows, 'volume-only — no daily rollups in window; run SupportDailyRollupAggregator for effort weighting'];
    }

    /** @param array<int,string> $templateTexts */
    private function hasMatchingTemplate(string $category, array $templateTexts): bool
    {
        if ($category === 'uncategorized' || $category === '') {
            return false;
        }

        $needle = mb_strtolower($category);

        foreach ($templateTexts as $text) {
            if (mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
