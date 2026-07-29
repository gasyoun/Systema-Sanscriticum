<?php

namespace App\Console\Commands;

use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Rank support topics by curator-time-saved potential to prioritise which
 * self-serve service to build first (payment page / access page / ORS-FAQ /
 * bot KB). The ranking metric is "curator-time saved" = query frequency ×
 * human effort per query, embodied directly in the per-category sum of human
 * curator replies (and, secondarily, first-response seconds).
 *
 * Data source: the already-classified support pipeline — SupportTopicAssignment
 * (keyword/AI category per chat-day) joined to SupportDailyRollup (per-chat,
 * per-day incoming / human-reply / ai-sent counts). No Telegram export needed;
 * this reads what the harvester + SupportTopicClassifier already stored.
 *
 * Primary (rollup-weighted) path needs SupportDailyRollupAggregator to have run
 * so daily rollups exist. If none fall in the window (e.g. a dev seed), it falls
 * back to ranking by raw assignment volume and says so.
 *
 * H1837 — the ranking is CROSS-CHANNEL: rollups now carry `channel`
 * (`telegram` = imported support account, `web` = SupportConversation over
 * chat_messages, incl. VK / TG-student-bot by `chat_messages.source`), and both
 * are counted by default. Before that, a topic asked mostly through the site
 * widget scored as if nobody asked it, which biased the "what to automate
 * first" order this command exists to produce. `--channel=` slices one channel;
 * the `web %` column shows where each topic actually arrives.
 */
class SupportTopicRanking extends Command
{
    protected $signature = 'support:topic-ranking
        {--months=6 : Look-back window in months (ignored when --since is given)}
        {--since= : Lower bound date YYYY-MM-DD (overrides --months)}
        {--until= : Upper bound date YYYY-MM-DD (defaults to today)}
        {--channel=all : Channel slice: all | telegram | web}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Rank support topics by curator-time-saved potential (frequency × human effort) over a window, to prioritise self-serve build order.';

    public function handle(): int
    {
        $until = $this->option('until')
            ? CarbonImmutable::parse($this->option('until'))
            : CarbonImmutable::now();
        $since = $this->option('since')
            ? CarbonImmutable::parse($this->option('since'))
            : $until->subMonths((int) $this->option('months'));

        $channel = strtolower(trim((string) $this->option('channel'))) ?: 'all';

        if (! in_array($channel, ['all', SupportDailyRollup::CHANNEL_TELEGRAM, SupportDailyRollup::CHANNEL_WEB], true)) {
            $this->error('--channel must be one of: all, telegram, web');

            return self::FAILURE;
        }

        [$rows, $mode] = $this->rankWeighted($since, $until, $channel);

        if ($rows->isEmpty()) {
            [$rows, $mode] = $this->rankVolumeOnly($since, $until, $channel);
        }

        // Deflection score: rollup-weighted = total curator replies (each is one
        // human touch we could remove); volume-only fallback = chat-day count.
        $rows = $rows->map(function ($r): array {
            $queries = (int) $r->queries;
            $ai = (int) $r->ai_sent;
            $human = (int) $r->human_replies;
            $webDays = (int) ($r->web_chat_days ?? 0);
            $chatDays = (int) $r->chat_days;

            return [
                'category' => $r->category ?: 'uncategorized',
                'chat_days' => $chatDays,
                'queries' => $queries,
                'human_replies' => $human,
                'ai_sent' => $ai,
                'unanswered' => (int) $r->unanswered,
                'curator_min' => (int) round(((int) $r->curator_seconds) / 60),
                // What fraction is already auto-handled — low = ripe for self-serve.
                'auto_rate' => ($ai + $human) > 0 ? round($ai / ($ai + $human), 2) : null,
                // Where the topic actually arrives (H1837). High web share on a
                // high-deflection topic = the self-serve path belongs on the site,
                // not in the bot KB.
                'web_share' => $chatDays > 0 ? round($webDays / $chatDays, 2) : null,
                'deflection' => $human > 0 ? $human : $chatDays,
            ];
        })->sortByDesc('deflection')->values();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'window' => ['since' => $since->toDateString(), 'until' => $until->toDateString()],
                'channel' => $channel,
                'mode' => $mode,
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Support topic ranking — %s → %s  [channel: %s]  (%s)',
            $since->toDateString(),
            $until->toDateString(),
            $channel,
            $mode,
        ));

        if ($rows->isEmpty()) {
            $this->warn('No classified support activity in this window.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'topic', 'chat-days', 'queries', 'human replies', 'ai sent', 'unanswered', 'curator min', 'auto %', 'web %', 'deflection'],
            $rows->values()->map(fn (array $r, int $i): array => [
                $i + 1,
                $r['category'],
                $r['chat_days'],
                $r['queries'] ?: '—',
                $r['human_replies'] ?: '—',
                $r['ai_sent'] ?: '—',
                $r['unanswered'] ?: '—',
                $r['curator_min'] ?: '—',
                $r['auto_rate'] === null ? '—' : (int) round($r['auto_rate'] * 100).'%',
                $r['web_share'] === null ? '—' : (int) round($r['web_share'] * 100).'%',
                $r['deflection'],
            ])->all(),
        );

        $this->newLine();
        $this->line('deflection = curator-time-saved rank (total human replies; chat-days when rollups absent).');
        $this->line('Highest deflection + lowest auto % = build a self-serve path for this topic first.');
        $this->line('web % = share of chat-days arriving through the site widget/VK/TG-bot rather than the TG support account.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: Collection, 1: string}
     */
    private function rankWeighted(CarbonImmutable $since, CarbonImmutable $until, string $channel): array
    {
        $rows = SupportTopicAssignment::query()
            ->join('support_daily_rollups as r', 'r.id', '=', 'support_topic_assignments.support_daily_rollup_id')
            ->whereBetween('r.conversation_date', [$since->toDateString(), $until->toDateString()])
            ->when($channel !== 'all', fn ($query) => $query->where('r.channel', $channel))
            ->groupBy('support_topic_assignments.category')
            ->selectRaw('support_topic_assignments.category as category')
            ->selectRaw('count(*) as chat_days')
            ->selectRaw('coalesce(sum(r.incoming_count),0) as queries')
            ->selectRaw('coalesce(sum(r.human_reply_count),0) as human_replies')
            ->selectRaw('coalesce(sum(r.ai_sent_count),0) as ai_sent')
            ->selectRaw('coalesce(sum(r.is_unanswered),0) as unanswered')
            ->selectRaw('coalesce(sum(r.first_response_seconds),0) as curator_seconds')
            ->selectRaw('sum(case when r.channel = ? then 1 else 0 end) as web_chat_days', [SupportDailyRollup::CHANNEL_WEB])
            ->get();

        return [$rows, 'rollup-weighted'];
    }

    /**
     * @return array{0: Collection, 1: string}
     */
    private function rankVolumeOnly(CarbonImmutable $since, CarbonImmutable $until, string $channel): array
    {
        $rows = SupportTopicAssignment::query()
            ->whereBetween('support_topic_assignments.created_at', [$since, $until])
            // Канал живёт на rollup'е, не на назначении темы: фильтруем join'ом
            // только когда срез запрошен, чтобы не менять план запроса «all».
            ->when($channel !== 'all', fn ($query) => $query
                ->join('support_daily_rollups as r', 'r.id', '=', 'support_topic_assignments.support_daily_rollup_id')
                ->where('r.channel', $channel))
            ->groupBy('support_topic_assignments.category')
            ->selectRaw('support_topic_assignments.category as category')
            ->selectRaw('count(*) as chat_days')
            ->selectRaw('0 as web_chat_days')
            ->selectRaw('0 as queries')
            ->selectRaw('0 as human_replies')
            ->selectRaw('0 as ai_sent')
            ->selectRaw('0 as unanswered')
            ->selectRaw('0 as curator_seconds')
            ->get();

        return [$rows, 'volume-only — no daily rollups in window; run SupportDailyRollupAggregator for effort weighting'];
    }
}
