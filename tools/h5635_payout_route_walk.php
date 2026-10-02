<?php

/** H5635 route-walk of «Проведение выплаты преподавателю» on the scratch sqlite stand. */
$_SERVER['APP_ENV'] = 'testing';
$_ENV['APP_ENV'] = 'testing';
$root = dirname(__DIR__); // репо-корень чекаута, откуда запущен скрипт (не хардкод машины)
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = 'Illuminate\\Contracts\\Console\\Kernel'; // строкой: pint fully_qualified_strict_types разворачивает FQCN в use-импорт, который в ненеймспейсном скрипте не работает
$app->make($kernel)->bootstrap();

use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Services\TeacherPayoutPoster;
use App\Services\TeacherSalaryService;
use Illuminate\Support\Facades\Artisan;

Artisan::call('migrate', ['--force' => true]);

$results = [];
$evidence = [];

// Fixture entities — test stand only, no real teachers/students/money.
$t1 = Teacher::create(['name' => 'Тестовый Препод H5635-1']);

// R1 — regular payout posts one negative salary_payout mirror, linked back.
$out = app(TeacherSalaryService::class)->recordManualPayout(
    $t1,
    ['amount' => 8000.0, 'paid_at' => '2026-10-02', 'period_month' => '2026-10', 'comment' => 'H5635 R1'],
    true, false, null
);
$p = $out['payout'];
$pay = Payment::find($p->payment_id);
$results['R1 regular payout → finance mirror'] = [
    $p->type === TeacherPayout::TYPE_REGULAR,
    $pay !== null && (float) $pay->amount === -8000.0,
    $pay !== null && $pay->tariff === TeacherPayoutPoster::TARIFF,
    $pay !== null && $pay->status === 'paid',
    $pay !== null && (int) $p->payment_id === (int) $pay->id,
    $pay !== null && str_starts_with((string) $pay->transaction_id, 'ЗП:'),
];
$evidence['R1 regular payout → finance mirror'] = "payout={$p->id} payment={$pay->id} amount={$pay->amount} tariff={$pay->tariff}";

// R2 — re-post is idempotent: same Payment updated, no duplicate.
$n = Payment::count();
app(TeacherPayoutPoster::class)->post($p);
$results['R2 re-post idempotent'] = [
    Payment::count() === $n,
    (int) $p->fresh()->payment_id === (int) $pay->id,
];
$evidence['R2 re-post idempotent'] = "payments={$n} (unchanged), payment_id={$p->fresh()->payment_id}";

// R3 — zero-amount payout posts nothing.
$z = $t1->payouts()->create(['amount' => 0, 'type' => TeacherPayout::TYPE_REGULAR, 'paid_at' => '2026-10-02']);
$nullPost = app(TeacherPayoutPoster::class)->post($z);
$results['R3 zero-amount → no mirror'] = [
    $nullPost === null,
    $z->fresh()->payment_id === null,
];
$evidence['R3 zero-amount → no mirror'] = 'post()=null, payment_id=null';

// R4 — unpost deletes the mirror and unlinks.
app(TeacherPayoutPoster::class)->unpost($p);
$results['R4 unpost removes mirror'] = [
    Payment::find($pay->id) === null,
    $p->fresh()->payment_id === null,
];
$evidence['R4 unpost removes mirror'] = "payment {$pay->id} deleted, payment_id=null";

// R5 — advance is real money out and hangs as unsettled.
$a = $t1->payouts()->create([
    'amount' => 5000.0, 'type' => TeacherPayout::TYPE_ADVANCE,
    'paid_at' => '2026-10-01', 'comment' => 'H5635 R5',
]);
$apay = app(TeacherPayoutPoster::class)->post($a);
$results['R5 advance posted + outstanding'] = [
    $apay !== null && (float) $apay->amount === -5000.0,
    $t1->payouts()->unsettledAdvances()->count() === 1,
    $a->fresh()->settled_at === null,
];
$evidence['R5 advance posted + outstanding'] = "advance={$a->id} mirror={$apay->id} unsettled=1";

// R6 — FIFO settle capped at payout amount; partial settlement stays unsettled.
$t2 = Teacher::create(['name' => 'Тестовый Препод H5635-2']);
$a1 = $t2->payouts()->create(['amount' => 3000.0, 'type' => TeacherPayout::TYPE_ADVANCE, 'paid_at' => '2026-10-01']);
$a2 = $t2->payouts()->create(['amount' => 5000.0, 'type' => TeacherPayout::TYPE_ADVANCE, 'paid_at' => '2026-10-02']);
$out2 = app(TeacherSalaryService::class)->recordManualPayout(
    $t2,
    ['amount' => 4000.0, 'paid_at' => '2026-10-03', 'period_month' => '2026-10', 'comment' => 'H5635 R6'],
    true, true, null
);
$s = $out2['settled'];
$a1f = $a1->fresh();
$a2f = $a2->fresh();
$mirror = Payment::find($out2['payout']->payment_id);
$results['R6 FIFO settle capped'] = [
    (float) $s['total'] === 4000.0,
    (float) $a1f->settled_amount === 3000.0 && $a1f->settled_at !== null,
    (float) $a2f->settled_amount === 1000.0 && $a2f->settled_at === null,
    $mirror !== null && (float) $mirror->amount === -4000.0,
];
$evidence['R6 FIFO settle capped'] = "settled_total={$s['total']} a1={$a1f->settled_amount}/settled_at=".($a1f->settled_at?->toDateString() ?? 'null')." a2={$a2f->settled_amount}/settled_at=".($a2f->settled_at?->toDateString() ?? 'null');

// R7 — settlement_key uniqueness rejects a replay.
$k = $t2->payouts()->create(['amount' => 1000.0, 'type' => TeacherPayout::TYPE_REGULAR, 'paid_at' => '2026-10-03', 'settlement_key' => 'H5635-KEY-1']);
$dupe = false;
try {
    $t2->payouts()->create(['amount' => 1000.0, 'type' => TeacherPayout::TYPE_REGULAR, 'paid_at' => '2026-10-03', 'settlement_key' => 'H5635-KEY-1']);
} catch (Throwable $e) {
    $dupe = str_contains(get_class($e), 'QueryException') || str_contains($e->getMessage(), 'UNIQUE');
}
$results['R7 settlement_key unique'] = [$dupe];
$evidence['R7 settlement_key unique'] = 'second insert with key H5635-KEY-1 rejected';

// R8 — payout without course lands on the technical course, excluded from salary base.
$p8 = $t1->payouts()->create(['amount' => 1500.0, 'type' => TeacherPayout::TYPE_REGULAR, 'paid_at' => '2026-10-03']);
$pay8 = app(TeacherPayoutPoster::class)->post($p8);
$tech = Course::where('slug', 'system-expenses')->first();
$results['R8 no-course → technical course'] = [
    $pay8 !== null && (int) $pay8->course_id === (int) $tech->id,
];
$evidence['R8 no-course → technical course'] = "payment {$pay8->id} course_id={$pay8->course_id} (system-expenses id={$tech->id})";

// Verdicts.
echo 'H5635 route walk — ', date('d.m.Y H:i:s'), " (stand: sqlite scratch, PAYMENT_FIX_WAVE1=true)\n\n";
$allPass = true;
foreach ($results as $route => $checks) {
    $pass = ! in_array(false, $checks, true);
    $allPass = $allPass && $pass;
    echo ($pass ? 'PASS' : 'FAIL'), "  {$route}\n";
    foreach ($checks as $i => $c) {
        if (! $c) {
            echo "      check #{$i} FAILED\n";
        }
    }
    echo "      evidence: {$evidence[$route]}\n";
}
echo "\n", $allPass ? 'ALL ROUTES PASS' : 'DISCREPANCIES FOUND', "\n";
// Scratch stand cleanup markers: leave rows in place — the db file is disposable.
