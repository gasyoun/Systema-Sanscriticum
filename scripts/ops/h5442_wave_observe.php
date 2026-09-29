<?php

/*
 | H5442 — read-only observation of the money P0 wave (PAYMENT_FIX_WAVE1) since
 | its prod enable time. Prints ids and counts only (no PII). Run on prod:
 |
 |   sudo -u www-data HOME=/tmp php artisan tinker scripts/ops/h5442_wave_observe.php
 |
 | Window starts at the prod enable time (app-local, Europe/Moscow)
 | 2026-09-24 16:22:51 (= 13:22:51 UTC). Writes nothing: every query is a
 | SELECT, the log scan only reads storage/logs/laravel-*.log.
 */

use App\Models\Payment;
use App\Models\TeacherPayout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$since = Carbon::parse('2026-09-24 16:22:51'); // prod enable time, app-local (Europe/Moscow)
$now = now();
$out = ['since' => $since->toDateTimeString(), 'observed_at' => $now->toDateTimeString(),
    'hours' => round($since->diffInMinutes($now) / 60, 2), 'wave_flag_live' => (bool) config('features.payment_fix_wave1')];

$new = Payment::query()->where('created_at', '>=', $since);
$out['payments_created'] = (clone $new)->selectRaw('provider, status, count(*) c')->groupBy('provider', 'status')->get()
    ->map(fn ($r) => $r->provider.'/'.$r->status.'='.$r->c)->values()->all();

// PayPal claims under the wave: amount verdicts + typed reconciliation exceptions.
$claims = (clone $new)->where('provider', Payment::PROVIDER_PAYPAL)->get(['id', 'status', 'claim_meta', 'claim_replay_key']);
$out['paypal_claims'] = [
    'total' => $claims->count(),
    'with_replay_key' => $claims->whereNotNull('claim_replay_key')->count(),
    'by_verdict' => $claims->countBy(fn ($p) => $p->claim_meta['amount_check']['verdict'] ?? 'none')->all(),
    'reconciliation_exceptions' => $claims->filter(fn ($p) => ! empty($p->claim_meta['reconciliation_exception']))
        ->map(fn ($p) => ['payment_id' => $p->id, 'status' => $p->status, 'type' => $p->claim_meta['reconciliation_exception']])->values()->all(),
    'paid_beyond_or_unpriced' => $claims->filter(fn ($p) => in_array($p->status, ['paid', 'success'], true)
        && in_array($p->claim_meta['amount_check']['verdict'] ?? null, ['beyond_5', 'no_expected_price'], true))->pluck('id')->all(),
];

// Silent access success: paid since enable, course has groups, user in none of them.
$paidSince = Payment::query()->whereIn('status', ['paid', 'success'])->where('updated_at', '>=', $since)
    ->whereNotNull('course_id')->whereNotNull('user_id')->get(['id', 'course_id', 'user_id']);
$silent = [];
$groupless = [];
foreach ($paidSince as $p) {
    $groupIds = DB::table('course_group')->where('course_id', $p->course_id)->pluck('group_id');
    if ($groupIds->isEmpty()) {
        $groupless[] = $p->id;

        continue;
    }
    if (! DB::table('group_user')->where('user_id', $p->user_id)->whereIn('group_id', $groupIds)->exists()) {
        $silent[] = $p->id;
    }
}
$out['access'] = ['paid_since' => $paidSince->count(), 'paid_on_groupless_course' => $groupless, 'paid_without_group_membership' => $silent];

// Duplicate money effects since enable.
$out['duplicates'] = [
    'paid_same_user_tariff_day' => DB::table('payments')->where('created_at', '>=', $since)->whereIn('status', ['paid', 'success'])
        ->whereNotNull('tariff')->selectRaw('user_id, course_id, tariff, date(created_at) d, count(*) c')
        ->groupBy('user_id', 'course_id', 'tariff', 'd')->having('c', '>', 1)->get()->map(fn ($r) => (array) $r)->all(),
    'replay_key_dupes' => DB::table('payments')->whereNotNull('claim_replay_key')->selectRaw('claim_replay_key, count(*) c')
        ->groupBy('claim_replay_key')->having('c', '>', 1)->count(),
];

$payouts = TeacherPayout::query()->where('created_at', '>=', $since)->get(['id', 'teacher_id', 'amount', 'settlement_key']);
$out['teacher_payouts'] = [
    'created' => $payouts->count(),
    'with_settlement_key' => $payouts->whereNotNull('settlement_key')->count(),
    'negative_amount_ids' => $payouts->filter(fn ($p) => (float) $p->amount < 0)->pluck('id')->values()->all(),
    'settlement_key_dupes' => DB::table('teacher_payouts')->whereNotNull('settlement_key')->selectRaw('settlement_key, count(*) c')
        ->groupBy('settlement_key')->having('c', '>', 1)->count(),
];

// Log scan: fail-closed throws and money exceptions since enable (counts only).
$patterns = ['grant_access_fail_closed' => 'нет привязанных групп', 'unique_violation' => 'UniqueConstraintViolation',
    'runtime_exception' => 'RuntimeException', 'paypal_claim' => 'PaypalClaim', 'payout_run' => 'PayoutRun'];
$counts = array_fill_keys(array_keys($patterns), 0);
for ($d = $since->copy()->startOfDay(); $d->lte($now); $d->addDay()) {
    $file = storage_path('logs/laravel-'.$d->format('Y-m-d').'.log');
    if (! is_file($file)) {
        continue;
    }
    foreach (new SplFileObject($file) as $line) {
        if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', (string) $line, $m) || Carbon::parse($m[1])->lt($since)) {
            continue;
        }
        foreach ($patterns as $k => $needle) {
            if (str_contains($line, $needle)) {
                $counts[$k]++;
            }
        }
    }
}
$out['log_counts_since'] = $counts;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
