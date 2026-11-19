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
    // Typed exceptions live in the queue (money_recon_exceptions), not in claim_meta.
    'reconciliation_exceptions' => $claims->isEmpty() ? [] : DB::table('money_recon_exceptions')
        ->whereIn('source_ref', $claims->map(fn ($p) => 'payment:'.$p->id)->all())
        ->get(['id', 'type', 'source_ref', 'state'])
        ->map(fn ($r) => ['payment_id' => (int) substr((string) $r->source_ref, 8), 'exception_id' => $r->id, 'type' => $r->type, 'state' => $r->state])
        ->values()->all(),
    'paid_beyond_or_unpriced' => $claims->filter(fn ($p) => in_array($p->status, ['paid', 'success'], true)
        && in_array($p->claim_meta['amount_check']['verdict'] ?? null, ['beyond_5', 'no_expected_price'], true))->pluck('id')->all(),
];

// Access is a lesson-level fact, not a group-membership fact (independent review,
// 26-10-2026). Group membership makes a course visible in the dashboard and
// group-bound lessons visible; a payment's tariff key opens lessons whose
// group_id is NULL — the normal case for courses without streams. A conditional
// promise grant is a real access key: ConditionalAccessGranter creates the
// payment and calls grantAccess(), so promise grants are classified here and
// never skipped. One lesson grant is not equivalent to a paid block or course.
$paidSince = Payment::query()->whereIn('status', ['paid', 'success'])->where('updated_at', '>=', $since)
    ->whereNotNull('course_id')->whereNotNull('user_id')->get(['id', 'course_id', 'user_id', 'tariff', 'amount', 'linked_promise_id']);
$adminIds = DB::table('users')->whereIn('role', ['super_admin', 'admin'])->pluck('id')->flip();
$silent = [];
$groupless = [];
$expense = [];
$admin = [];
$partial = [];
$noScope = [];
$notYetDue = [];
$promiseGrants = [];
$courseFormat = [];
$lessonCache = [];
$groupCache = [];
$grantCache = [];
$lessonsOf = function (int $courseId) use (&$lessonCache) {
    return $lessonCache[$courseId] ??= DB::table('lessons')->where('course_id', $courseId)
        ->get(['id', 'group_id', 'is_free', 'is_preview', 'is_published', 'block_number', 'block_half']);
};
$groupsOf = function (int $userId) use (&$groupCache) {
    return $groupCache[$userId] ??= DB::table('group_user')->where('user_id', $userId)->pluck('group_id')->flip();
};
$grantsOf = function (int $userId, int $courseId) use (&$grantCache) {
    return $grantCache[$userId.':'.$courseId] ??= DB::table('lesson_access_grants')
        ->where('user_id', $userId)->where('course_id', $courseId)->whereNull('revoked_at')
        ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->pluck('lesson_id')->flip();
};
$inScope = function ($lesson, ?string $tariff): bool {
    if ($tariff === null || $tariff === 'full') {
        return true;
    }
    if (! str_starts_with($tariff, 'block_')) {
        return false;
    }
    [$block, $half] = array_pad(explode('_h', substr($tariff, 6), 2), 2, null);

    return (string) $lesson->block_number === (string) $block
        && ($half === null || (string) $lesson->block_half === (string) $half);
};
// Paid scope of one payment vs the paid lessons actually open to that student.
$access = function ($p) use ($lessonsOf, $groupsOf, $grantsOf, $inScope): array {
    $scope = 0;
    $open = 0;
    $groups = $groupsOf((int) $p->user_id);
    $grants = $grantsOf((int) $p->user_id, (int) $p->course_id);
    foreach ($lessonsOf((int) $p->course_id) as $l) {
        if (! $inScope($l, $p->tariff) || $l->is_free || $l->is_preview || ! $l->is_published) {
            continue; // free/unpublished content is not delivery of a paid scope
        }
        $scope++;
        $visible = $l->group_id === null || isset($groups[$l->group_id]) || isset($grants[$l->id]);
        if (! $visible) {
            continue;
        }
        $keyed = $p->tariff === 'full'
            || $p->tariff === 'block_'.$l->block_number
            || ($l->block_half && $p->tariff === 'block_'.$l->block_number.'_h'.$l->block_half);
        if ($keyed || isset($grants[$l->id])) {
            $open++;
        }
    }

    return ['scope' => $scope, 'open' => $open];
};
foreach ($paidSince as $p) {
    if ($p->tariff === 'salary_payout') {
        $expense[] = $p->id;

        continue;
    }
    $courseFormat[$p->course_id] ??= (string) DB::table('courses')->where('id', $p->course_id)->value('format');
    if ($courseFormat[$p->course_id] === 'enrolling') {
        $notYetDue[] = $p->id;

        continue;
    }
    $a = $access($p);
    if ($p->linked_promise_id !== null) {
        $promise = DB::table('payment_promises')->where('id', $p->linked_promise_id)->first(['status', 'promised_at']);
        $promiseGrants[] = [
            'payment_id' => $p->id,
            'promise_id' => (int) $p->linked_promise_id,
            'promise_status' => $promise->status ?? null,
            'promised_at' => $promise->promised_at ?? null,
            'paid_lessons_in_scope' => $a['scope'],
            'open_paid_lessons' => $a['open'],
        ];
    }
    if ($a['scope'] === 0) {
        $noScope[] = $p->id;

        continue;
    }
    if ($a['open'] === $a['scope']) {
        continue; // the whole paid scope is open
    }
    if ($a['open'] > 0) {
        $partial[] = ['payment_id' => $p->id, 'open' => $a['open'], 'scope' => $a['scope']];

        continue;
    }
    // Nothing open: classify the reason before calling it a delivery failure.
    $groupIds = DB::table('course_group')->where('course_id', $p->course_id)->pluck('group_id');
    if ($groupIds->isEmpty()) {
        $groupless[] = $p->id;

        continue;
    }
    if (isset($adminIds[$p->user_id])) {
        $admin[] = $p->id;

        continue;
    }
    $silent[] = $p->id;
}
$out['access'] = [
    'paid_since' => $paidSince->count(),
    'paid_on_groupless_course' => $groupless,
    'paid_without_group_membership' => $silent,
    'classified' => [
        'salary_payout_expense' => $expense,
        'admin_account' => $admin,
        'partially_delivered' => $partial,
        'no_paid_scope' => $noScope,
        'course_enrolling' => $notYetDue,
    ],
    // Every promise-grant row, delivered or not, with the lesson-level verdict.
    // Delivered rows are not repeated in paid_without_group_membership.
    'promise_grants' => $promiseGrants,
];

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
// Coverage is reported, not assumed: a missing daily file would otherwise read as
// "zero matches" and be misquoted as "zero errors".
$patterns = ['grant_access_fail_closed' => 'нет привязанных групп', 'unique_violation' => 'UniqueConstraintViolation',
    'runtime_exception' => 'RuntimeException', 'paypal_claim' => 'PaypalClaim', 'payout_run' => 'PayoutRun'];
$counts = array_fill_keys(array_keys($patterns), 0);
$logPresent = 0;
$logMissing = [];
for ($d = $since->copy()->startOfDay(); $d->lte($now); $d->addDay()) {
    $file = storage_path('logs/laravel-'.$d->format('Y-m-d').'.log');
    if (! is_file($file)) {
        $logMissing[] = $d->format('Y-m-d');

        continue;
    }
    $logPresent++;
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
$out['log_coverage'] = [
    'days' => $logPresent + count($logMissing),
    'files_present' => $logPresent,
    'files_missing' => $logMissing,
    'complete' => $logMissing === [],
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
