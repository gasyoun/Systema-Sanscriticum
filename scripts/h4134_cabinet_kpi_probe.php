<?php

declare(strict_types=1);

/**
 * H4134 — post-flip cabinet adoption / revenue KPI probe (READ-ONLY).
 *
 * Runs on prod against the live DB and prints AGGREGATES ONLY — no emails,
 * names, Telegram IDs or student-level rows. Every query is a SELECT; the
 * script never writes, sends, or flips a flag.
 *
 * Windows are anchored on the DEPLOY №52 flag flip (CABINET_HYBRID=true,
 * 2026-08-21 07:51:25Z, backup .env.bak.h-cabinet-hybrid.20260821T075125) so
 * post-flip day-7 / day-14 are comparable with the mirrored pre-flip windows.
 *
 *   ssh root@193.232.229.92
 *   cd /var/www/html && php scripts/h4134_cabinet_kpi_probe.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$flip = '2026-08-21 07:51:25';
$d7 = '2026-08-28 07:51:25';
$d14 = '2026-09-04 07:51:25';
$pre7 = '2026-08-14 07:51:25';
$pre14 = '2026-08-07 07:51:25';

$windows = [
    ['POSTFLIP_D1_7', $flip, $d7],
    ['POSTFLIP_D1_14', $flip, $d14],
    ['PREFLIP_7', $pre7, $flip],
    ['PREFLIP_14', $pre14, $flip],
];

foreach ($windows as [$label, $from, $to]) {
    echo "== {$label} ({$from} -> {$to})\n";
    $rows = DB::table('activity_events')
        ->selectRaw('event_type, COUNT(*) n, COUNT(DISTINCT user_id) u')
        ->whereBetween('created_at', [$from, $to])
        ->groupBy('event_type')
        ->orderBy('event_type')
        ->get();
    foreach ($rows as $r) {
        printf("  %-34s %6d %5d\n", $r->event_type, $r->n, $r->u);
    }
}

echo "== DENOMINATORS\n";
$accessTotal = DB::table('users')->where('note', 'like', '%[Доступ отправлен%')->count();
$accessEver = DB::table('users')->where('note', 'like', '%[Доступ отправлен%')->where('login_count', '>', 0)->count();
$access30 = DB::table('users')->where('note', 'like', '%[Доступ отправлен%')->where('last_login_at', '>=', now()->subDays(30))->count();
printf("  access_granted_total %d\n  access_ever_login %d (%.1f%%)\n  access_active_30d %d\n",
    $accessTotal, $accessEver, $accessTotal ? 100 * $accessEver / $accessTotal : 0, $access30);

$paidIds = DB::table('payments')->where('status', 'paid')->distinct()->pluck('user_id')->filter()->values();
$paidTotal = $paidIds->count();
$paidEver = DB::table('users')->whereIn('id', $paidIds)->where('login_count', '>', 0)->count();
$paid30 = DB::table('users')->whereIn('id', $paidIds)->where('last_login_at', '>=', now()->subDays(30))->count();
printf("  paid_users %d\n  paid_ever_login %d (%.1f%%)\n  paid_active_30d %d\n",
    $paidTotal, $paidEver, $paidTotal ? 100 * $paidEver / $paidTotal : 0, $paid30);

echo "== INVITE COHORT\n";
$inv = DB::table('users')->whereNotNull('cabinet_invite_sent_at')->count();
$invNever = DB::table('users')->whereNotNull('cabinet_invite_sent_at')->where('login_count', '=', 0)->count();
$invAfter = DB::table('users')->whereNotNull('cabinet_invite_sent_at')->whereColumn('last_login_at', '>=', 'cabinet_invite_sent_at')->count();
printf("  invite_sent_total %d\n  still_never_login %d\n  login_after_stamp %d (%.1f%%)\n",
    $inv, $invNever, $invAfter, $inv ? 100 * $invAfter / $inv : 0);

echo "== MONEY (payments.status=paid)\n";
foreach ($windows as [$label, $from, $to]) {
    $q = DB::table('payments')->where('status', 'paid')->whereBetween('first_paid_at', [$from, $to]);
    $n = (clone $q)->count();
    $sum = (clone $q)->sum('amount');
    $u = (clone $q)->distinct()->count('user_id');
    printf("  %-16s payments %4d  payers %4d  sum %14s\n", $label, $n, $u, number_format((float) $sum, 0, '.', ' '));
}

echo "== REPEAT PAID (users with >1 paid payment inside the window)\n";
foreach ([$windows[1], $windows[3]] as [$label, $from, $to]) {
    $repeat = DB::table('payments')->where('status', 'paid')->whereBetween('first_paid_at', [$from, $to])
        ->selectRaw('user_id, COUNT(*) c')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->get()->count();
    printf("  %-16s repeat_payers %d\n", $label, $repeat);
}

echo "== SUPPORT (support_conversations rows in window)\n";
foreach ($windows as [$label, $from, $to]) {
    $c = DB::table('support_conversations')->whereBetween('created_at', [$from, $to])->count();
    printf("  %-16s support_conversations %d\n", $label, $c);
}

echo "DONE\n";
