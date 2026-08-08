<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\FollowUpTask;
use App\Models\PaymentPromise;
use App\Models\SupportConversation;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\SupportVisitorPresence;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * H2382 — production-safe aggregate parity readout for support channels.
 *
 * Read-only. Never touches message bodies, student PII, or per-student exports.
 * Denominators are always printed so empty windows cannot look like green KPIs.
 */
class SupportParityReportBuilder
{
    /**
     * Feature keys that gate a JIVO-parity lane. Value is the env/config path
     * under features.* (boolean). A disabled flag is code-complete/config-off.
     *
     * @var list<string>
     */
    public const FEATURE_KEYS = [
        'support_unified_reply',
        'support_ai_assist',
        'support_ai_include_telegram',
        'support_answer_suggester',
        'support_template_drafts',
        'support_visitor_geo',
        'support_visitor_presence',
        'support_lead_capture',
        'support_observability',
        'support_web_rollups',
        'crm_cockpit',
        'support_required_close_topic',
        'support_follow_up_tasks',
    ];

    /**
     * @return array{
     *     generated_at: string,
     *     days: int,
     *     window: array{since: string, until: string},
     *     privacy: array{rule: string, exports: string},
     *     flags: array<string, bool|string|null>,
     *     rollback: array<string, string>,
     *     channel_mix: array<string, array<string, int|float|null>>,
     *     kpis: array<string, int|float|null>,
     *     topics: list<array<string, int|float|string|null>>,
     *     outcome_slices: array<string, int|float|null>,
     *     infrastructure: array<string, mixed>,
     *     verdict: array{status: string, reasons: list<string>}
     * }
     */
    public function build(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $until = CarbonImmutable::now(config('app.timezone'));
        $since = $until->subDays($days - 1)->startOfDay();

        $flags = $this->flags();
        $channelMix = $this->channelMix($since);
        $kpis = $this->kpis($since, $channelMix);
        $topics = $this->topics($since, $until);
        $outcomes = $this->outcomeSlices($since, $until, $topics);
        $infra = $this->infrastructure($since);
        $verdict = $this->verdict($flags, $kpis, $infra, $channelMix);

        return [
            'generated_at' => now()->toIso8601String(),
            'days' => $days,
            'window' => [
                'since' => $since->toDateString(),
                'until' => $until->toDateString(),
            ],
            'privacy' => [
                'rule' => 'Aggregate channel/topic counts only; no per-student rows, no message bodies, no IP export.',
                'exports' => 'none',
            ],
            'flags' => $flags,
            'rollback' => $this->rollbackMap(),
            'channel_mix' => $channelMix,
            'kpis' => $kpis,
            'topics' => $topics,
            'outcome_slices' => $outcomes,
            'infrastructure' => $infra,
            'verdict' => $verdict,
        ];
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function flags(): array
    {
        $out = [];
        foreach (self::FEATURE_KEYS as $key) {
            $out[$key] = (bool) config('features.'.$key);
        }
        $out['support_geo.driver'] = config('support_geo.driver');
        $out['services.telegram_support.enabled'] = (bool) config('services.telegram_support.enabled');
        $out['support.homework_pause_note.enabled'] = (bool) config('support.homework_pause_note.enabled', true);
        $out['support.rollup.unresolved_after_hours'] = (int) config('support.rollup.unresolved_after_hours', 24);

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function rollbackMap(): array
    {
        return [
            'web_widget_realtime' => 'Disable Reverb/broadcast on public widget (ops); fall back to poll is built-in.',
            'support_unified_reply' => 'SUPPORT_UNIFIED_REPLY=false → config:clear (TG userbot reply path off; web/bot replies unchanged).',
            'support_ai_assist' => 'SUPPORT_AI_ASSIST=false (drafts stop; never auto-sends).',
            'support_answer_suggester' => 'SUPPORT_ANSWER_SUGGESTER=false and/or MarketingSetting.support_answer_suggester_enabled=false.',
            'support_visitor_geo' => 'SUPPORT_VISITOR_GEO=false (entry_url/referrer may still write; city resolve stops).',
            'support_visitor_presence' => 'SUPPORT_VISITOR_PRESENCE=false (beacon no-ops; VisitorsOnline hidden).',
            'support_lead_capture' => 'SUPPORT_LEAD_CAPTURE=false (contact fields hidden).',
            'support_web_rollups' => 'SUPPORT_WEB_ROLLUPS=false (support:rollup-web becomes no-op; TG rollups continue).',
            'support_observability' => 'SUPPORT_OBSERVABILITY=false (dashboard hidden; metrics tables remain).',
            'support_required_close_topic' => 'SUPPORT_REQUIRED_CLOSE_TOPIC=false (close without topic allowed again).',
            'support_follow_up_tasks' => 'SUPPORT_FOLLOW_UP_TASKS=false (Helpdesk dialog follow-ups hidden).',
            'support_geo.driver' => 'SUPPORT_GEO_DRIVER=null (or remove) — city resolve stops; flag may stay on.',
            'telegram_support_sync' => 'TELEGRAM_SUPPORT_ENABLED=false or disable TelegramSupportAccount.is_enabled.',
            'email_channel' => 'N/A — inbound email channel not implemented (H1200 residual).',
        ];
    }

    /**
     * @return array<string, array{conversations: int, incoming: int, outgoing: int, unanswered: int, unresolved_after_hours: int, avg_first_response_seconds: int|null}>
     */
    private function channelMix(CarbonImmutable $since): array
    {
        $rollups = SupportDailyRollup::query()
            ->whereDate('conversation_date', '>=', $since->toDateString())
            ->get([
                'channel', 'is_unanswered', 'unresolved_after_hours',
                'first_response_seconds', 'incoming_count', 'outgoing_count',
            ]);

        $byChannel = $rollups->groupBy(fn (SupportDailyRollup $r): string => (string) ($r->channel ?: 'unknown'));

        $out = [];
        foreach ($byChannel as $channel => $group) {
            $out[$channel] = $this->foldRollups($group);
        }

        // Explicit zero rows for known channels missing in the window so the
        // matrix cannot hide "VK silent" as "not measured".
        foreach ([
            SupportDailyRollup::CHANNEL_TELEGRAM,
            SupportDailyRollup::CHANNEL_WEB,
            SupportDailyRollup::CHANNEL_VK,
            SupportDailyRollup::CHANNEL_TELEGRAM_BOT,
        ] as $known) {
            if (! array_key_exists($known, $out)) {
                $out[$known] = $this->emptyChannelRow();
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  Collection<int, SupportDailyRollup>  $rollups
     * @return array{conversations: int, incoming: int, outgoing: int, unanswered: int, unresolved_after_hours: int, avg_first_response_seconds: int|null}
     */
    private function foldRollups(Collection $rollups): array
    {
        $withResponse = $rollups->whereNotNull('first_response_seconds');

        return [
            'conversations' => $rollups->count(),
            'incoming' => (int) $rollups->sum('incoming_count'),
            'outgoing' => (int) $rollups->sum('outgoing_count'),
            'unanswered' => $rollups->where('is_unanswered', true)->count(),
            'unresolved_after_hours' => $rollups->where('unresolved_after_hours', true)->count(),
            'avg_first_response_seconds' => $withResponse->isNotEmpty()
                ? (int) round((float) $withResponse->avg('first_response_seconds'))
                : null,
        ];
    }

    /**
     * @return array{conversations: int, incoming: int, outgoing: int, unanswered: int, unresolved_after_hours: int, avg_first_response_seconds: int|null}
     */
    private function emptyChannelRow(): array
    {
        return [
            'conversations' => 0,
            'incoming' => 0,
            'outgoing' => 0,
            'unanswered' => 0,
            'unresolved_after_hours' => 0,
            'avg_first_response_seconds' => null,
        ];
    }

    /**
     * @param  array<string, array<string, int|float|null>>  $channelMix
     * @return array<string, int|float|null>
     */
    private function kpis(CarbonImmutable $since, array $channelMix): array
    {
        $totals = $this->emptyChannelRow();
        foreach ($channelMix as $row) {
            $totals['conversations'] += (int) $row['conversations'];
            $totals['incoming'] += (int) $row['incoming'];
            $totals['outgoing'] += (int) $row['outgoing'];
            $totals['unanswered'] += (int) $row['unanswered'];
            $totals['unresolved_after_hours'] += (int) $row['unresolved_after_hours'];
        }

        $openThreads = SupportConversation::query()
            ->where('status', '!=', SupportConversation::STATUS_CLOSED)
            ->count();
        $closedThreads = SupportConversation::query()
            ->where('status', SupportConversation::STATUS_CLOSED)
            ->count();
        $staleOpen = SupportConversation::query()
            ->where('status', '!=', SupportConversation::STATUS_CLOSED)
            ->where('last_message_at', '<', now()->subHours(24))
            ->count();
        $withCity = SupportConversation::query()->whereNotNull('visitor_city')->count();
        $withEntry = SupportConversation::query()->whereNotNull('entry_url')->count();
        $leads = SupportConversation::query()->whereNotNull('lead_captured_at')->count();

        $chatBySource = ChatMessage::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('coalesce(source, ?) as src, count(*) as c', ['web'])
            ->groupBy('src')
            ->pluck('c', 'src')
            ->map(fn ($v) => (int) $v)
            ->all();

        $presences = Schema::hasTable('support_visitor_presences')
            ? SupportVisitorPresence::query()->count()
            : 0;

        return [
            'rollup_conversations' => $totals['conversations'],
            'rollup_incoming' => $totals['incoming'],
            'rollup_outgoing' => $totals['outgoing'],
            'rollup_unanswered' => $totals['unanswered'],
            'unresolved_after_hours' => $totals['unresolved_after_hours'],
            'open_threads' => $openThreads,
            'closed_threads' => $closedThreads,
            'stale_open_threads_gt_24h' => $staleOpen,
            'threads_with_visitor_city' => $withCity,
            'threads_with_entry_url' => $withEntry,
            'threads_with_lead_capture' => $leads,
            'chat_messages_by_source' => $chatBySource,
            'visitor_presences_live' => $presences,
            'open_follow_up_tasks_crm' => FollowUpTask::query()->open()->count(),
        ];
    }

    /**
     * @return list<array{category: string, chat_days: int, queries: int, human_replies: int, unanswered: int, web_share: float|null}>
     */
    private function topics(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $rows = SupportTopicAssignment::query()
            ->join('support_daily_rollups as r', 'r.id', '=', 'support_topic_assignments.support_daily_rollup_id')
            ->whereDate('r.conversation_date', '>=', $since->toDateString())
            ->whereDate('r.conversation_date', '<=', $until->toDateString())
            ->groupBy('support_topic_assignments.category')
            ->orderByDesc('chat_days')
            ->get([
                'support_topic_assignments.category',
                \DB::raw('COUNT(DISTINCT r.id) as chat_days'),
                \DB::raw('SUM(r.incoming_count) as queries'),
                \DB::raw('SUM(r.human_reply_count) as human_replies'),
                \DB::raw('SUM(CASE WHEN r.is_unanswered THEN 1 ELSE 0 END) as unanswered'),
                \DB::raw('SUM(CASE WHEN r.channel IN (\'web\',\'vk\',\'telegram_bot\') THEN 1 ELSE 0 END) as web_days'),
            ]);

        return $rows->map(function ($r): array {
            $chatDays = (int) $r->chat_days;
            $webDays = (int) $r->web_days;

            return [
                'category' => $r->category ?: 'uncategorized',
                'chat_days' => $chatDays,
                'queries' => (int) $r->queries,
                'human_replies' => (int) $r->human_replies,
                'unanswered' => (int) $r->unanswered,
                'web_share' => $chatDays > 0 ? round($webDays / $chatDays, 2) : null,
            ];
        })->values()->all();
    }

    /**
     * Aggregate outcome-adjacent slices — topic buckets vs school-wide payment
     * promise / access-ish load. No student-level join, no export.
     *
     * @param  list<array{category: string, chat_days: int, queries: int, human_replies: int, unanswered: int, web_share: float|null}>  $topics
     * @return array<string, int|float|null>
     */
    private function outcomeSlices(CarbonImmutable $since, CarbonImmutable $until, array $topics): array
    {
        $topicMap = collect($topics)->keyBy('category');
        $accessDays = (int) ($topicMap->get('access')['chat_days'] ?? 0);
        $paymentDays = (int) ($topicMap->get('payment')['chat_days'] ?? 0)
            + (int) ($topicMap->get('refund')['chat_days'] ?? 0);
        $scheduleDays = (int) ($topicMap->get('schedule')['chat_days'] ?? 0);
        $totalDays = max(1, (int) collect($topics)->sum('chat_days'));

        $openPromises = PaymentPromise::query()
            ->whereIn('status', [PaymentPromise::STATUS_ACTIVE, PaymentPromise::STATUS_EXPIRED])
            ->count();
        $promisesInWindow = PaymentPromise::query()
            ->whereBetween('created_at', [$since, $until->endOfDay()])
            ->count();
        $overduePromises = PaymentPromise::query()->overdue()->count();

        return [
            'topic_access_chat_days' => $accessDays,
            'topic_payment_chat_days' => $paymentDays,
            'topic_schedule_chat_days' => $scheduleDays,
            'topic_access_share' => round($accessDays / $totalDays, 3),
            'topic_payment_share' => round($paymentDays / $totalDays, 3),
            'open_payment_promises_schoolwide' => $openPromises,
            'payment_promises_created_in_window' => $promisesInWindow,
            'overdue_payment_promises_schoolwide' => $overduePromises,
            'note' => 'Correlation is side-by-side aggregates (topic volume vs promise load), not a per-student join. Support-dialog follow-ups are H2381 residual — FollowUpTask counts are CRM/deal only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function infrastructure(CarbonImmutable $since): array
    {
        $staleAfter = (int) config('services.telegram_support.stale_after_minutes', 15);

        $accounts = TelegramSupportAccount::query()
            ->orderBy('name')
            ->get()
            ->map(function (TelegramSupportAccount $account) use ($staleAfter): array {
                $lag = $account->last_successful_sync_at
                    ? (int) $account->last_successful_sync_at->diffInMinutes(now())
                    : null;

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'is_enabled' => (bool) $account->is_enabled,
                    'lag_minutes' => $lag,
                    'is_stale' => (bool) $account->is_enabled
                        && ($lag === null || $lag > $staleAfter),
                    'last_sync_error' => $account->last_sync_error ? '[redacted]' : null,
                ];
            })
            ->all();

        $outgoing = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('sent_at', '>=', $since)
            ->get(['id', 'raw_payload', 'telegram_message_id']);

        $tracked = $outgoing->filter(
            fn (TelegramSupportMessage $m): bool => array_key_exists('pending_delivery', $m->raw_payload ?? []),
        );
        $pending = $tracked->filter(
            fn (TelegramSupportMessage $m): bool => (bool) ($m->raw_payload['pending_delivery'] ?? false),
        );

        return [
            'telegram_accounts' => $accounts,
            'delivery_14d_window' => [
                'outgoing_total' => $outgoing->count(),
                'tracked_pending_flag' => $tracked->count(),
                'pending' => $pending->count(),
                'delivered_tracked' => $tracked->count() - $pending->count(),
                'with_telegram_message_id' => $outgoing->whereNotNull('telegram_message_id')->count(),
            ],
            'email_inbound' => 'not_implemented',
            'vk_messages_all_time' => ChatMessage::query()->where('source', 'vk')->count(),
        ];
    }

    /**
     * @param  array<string, bool|string|null>  $flags
     * @param  array<string, int|float|null>  $kpis
     * @param  array<string, mixed>  $infra
     * @param  array<string, array<string, int|float|null>>  $channelMix
     * @return array{status: string, reasons: list<string>}
     */
    private function verdict(array $flags, array $kpis, array $infra, array $channelMix): array
    {
        $reasons = [];

        if (! ($flags['support_web_rollups'] ?? false)) {
            $reasons[] = 'support_web_rollups OFF — web/VK/TG-bot rollups not written (code-complete/config-off).';
        }
        $geoDriver = $flags['support_geo.driver'] ?? null;
        $geoDriverOff = $geoDriver === null || $geoDriver === '' || $geoDriver === 'null';
        if (($flags['support_visitor_geo'] ?? false) && $geoDriverOff) {
            $reasons[] = 'support_visitor_geo ON but support_geo.driver null — city resolve never runs.';
        }
        if ((int) ($kpis['threads_with_visitor_city'] ?? 0) === 0 && ($flags['support_visitor_geo'] ?? false)) {
            $reasons[] = 'Zero threads with visitor_city despite geo flag — not verified-live for city.';
        }
        if ((int) ($kpis['threads_with_lead_capture'] ?? 0) === 0 && ($flags['support_lead_capture'] ?? false)) {
            $reasons[] = 'Lead capture flag ON but zero lead_captured_at threads — no live canary proof.';
        }
        if ((int) ($kpis['visitor_presences_live'] ?? 0) === 0 && ($flags['support_visitor_presence'] ?? false)) {
            $reasons[] = 'Presence flag ON but zero live presence rows at report time.';
        }
        if ((int) ($kpis['closed_threads'] ?? 0) === 0 && (int) ($kpis['open_threads'] ?? 0) > 0) {
            $reasons[] = 'No closed SupportConversation rows — close/topic workflow not exercised in prod.';
        }
        if ((int) ($channelMix[SupportDailyRollup::CHANNEL_VK]['conversations'] ?? 0) === 0
            && (int) ($infra['vk_messages_all_time'] ?? 0) === 0) {
            $reasons[] = 'VK channel: code+badging present, zero traffic all-time — not verified-live.';
        }
        if (($infra['email_inbound'] ?? null) === 'not_implemented') {
            $reasons[] = 'Inbound email channel not implemented (H1200 residual).';
        }
        if ((int) ($infra['delivery_14d_window']['pending'] ?? 0) > 0) {
            $reasons[] = 'Tracked TG reply-out still has pending_delivery rows in window.';
        }
        foreach ($infra['telegram_accounts'] as $account) {
            if (! empty($account['is_stale']) && ! empty($account['is_enabled'])) {
                $reasons[] = 'Telegram account '.$account['name'].' enabled but stale sync.';
            }
        }
        $reasons[] = 'H2381 operator workflow (required close-topic + dialog-linked follow-ups + full EdTech sidebar) still open — full parity blocked.';
        $reasons[] = 'H1200 reply-out human canary + inbound email still residual — full parity blocked.';

        $status = $reasons === [] ? 'GO' : 'HOLD';

        return [
            'status' => $status,
            'reasons' => $reasons,
        ];
    }
}
