<?php

declare(strict_types=1);

/*
 * H5443 (P1): гонка двух возвратов на настоящей MariaDB — только на scratch-базе.
 *
 *   php scripts/h5443_ledger_concurrency_probe.php setup
 *   php scripts/h5443_ledger_concurrency_probe.php service <receiptId> <key> <kopecks> <barrierEpoch>
 *   php scripts/h5443_ledger_concurrency_probe.php stale   <receiptId> <key> <kopecks> <barrierEpoch>
 *   php scripts/h5443_ledger_concurrency_probe.php raw     <receiptId> <key> <kopecks> <barrierEpoch>
 *   php scripts/h5443_ledger_concurrency_probe.php check
 *
 * `service` — обычный верхнеуровневый вызов LedgerService (как в проде): оба
 * участника стартуют по общему барьеру. `stale` и `raw` сначала открывают свою
 * транзакцию и делают чтение (фиксируют снимок REPEATABLE READ), ждут барьера
 * и только потом возвращают деньги — худший случай «устаревшего снимка»;
 * `stale` зовёт сервис внутри этой транзакции, `raw` — голый INSERT, только триггер. Отказывается работать,
 * если DB_DATABASE не содержит «scratch».
 */

use App\Models\Course;
use App\Models\MoneyMovement;
use App\Models\User;
use App\Services\Ledger\LedgerInvariantViolation;
use App\Services\Ledger\LedgerProjection;
use App\Services\Ledger\LedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.'.config('database.default').'.database'), 'scratch')) {
    fwrite(STDERR, "refuse: DB_DATABASE must be a scratch database\n");
    exit(2);
}
config(['features.money_ledger_core' => true]);

$ledger = app(LedgerService::class);
$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $r = $ledger->receipt('race:receipt:'.uniqid(), 10000, $user->id, $course->id, now());
    echo $r->id, PHP_EOL;
    exit(0);
}

if ($mode === 'check') {
    $p = app(LedgerProjection::class);
    echo json_encode(['breaches' => $p->integrityBreaches(), 'totals' => $p->controlTotals()]), PHP_EOL;
    exit(0);
}

[$id, $key, $kopecks, $barrier] = [(int) $argv[2], (string) $argv[3], (int) $argv[4], (float) $argv[5]];

try {
    if ($mode === 'service') {
        while (microtime(true) < $barrier) {
            usleep(500);
        }
        $ledger->refund(MoneyMovement::query()->findOrFail($id), $key, $kopecks, now());
        echo "OK {$mode} {$key}", PHP_EOL;
        exit(0);
    }
    DB::transaction(function () use ($mode, $ledger, $id, $key, $kopecks, $barrier): void {
        DB::select('SELECT COUNT(*) AS n FROM money_movements'); // снимок до барьера
        while (microtime(true) < $barrier) {
            usleep(500);
        }
        if ($mode === 'stale') {
            $ledger->refund(MoneyMovement::query()->findOrFail($id), $key, $kopecks, now());

            return;
        }
        $src = DB::table('money_movements')->where('id', $id)->first();
        DB::table('money_movements')->insert([
            'movement_key' => $key,
            'type' => MoneyMovement::REFUND,
            'amount_kopecks' => -$kopecks,
            'user_id' => $src->user_id,
            'course_id' => $src->course_id,
            'refund_of_movement_id' => $id,
            'cap_anchor_id' => $id,
            'source_currency' => 'RUB',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        usleep(300_000); // держим транзакцию открытой, чтобы вторая вставка шла внахлёст
    });
    echo "OK {$mode} {$key}", PHP_EOL;
} catch (DeadlockException $e) {
    echo "ABORTED-SAFE {$mode} {$key}: ", preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 110)), PHP_EOL;
} catch (LedgerInvariantViolation|QueryException $e) {
    echo "REJECTED {$mode} {$key}: ", preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 110)), PHP_EOL;
}
