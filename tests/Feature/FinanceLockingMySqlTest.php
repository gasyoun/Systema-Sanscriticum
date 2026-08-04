<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MutualSettlement;
use App\Models\PaymentPromise;
use App\Models\Teacher;
use App\Models\User;
use App\Services\MutualSettlementService;
use App\Services\PromiseFulfillment;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Opt-in integration probes for real InnoDB row locks. The normal suite uses
 * SQLite in-memory, whose grammar cannot exercise SELECT ... FOR UPDATE.
 *
 * Run against an already migrated disposable MySQL test database:
 * RUN_MYSQL_CONCURRENCY_TESTS=1 php artisan test --filter=FinanceLockingMySqlTest
 */
class FinanceLockingMySqlTest extends TestCase
{
    private ?Connection $probe = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_MYSQL_CONCURRENCY_TESTS=1 to run InnoDB lock probes.');
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('InnoDB lock probes require the mysql driver.');
        }

        Queue::fake();
        Mail::fake();
    }

    /** @test */
    public function promise_and_settlement_writers_wait_on_their_innodb_locks(): void
    {
        $database = DB::connection()->getDatabaseName();
        $this->assertStringContainsStringIgnoringCase('test', $database, 'Use a disposable MySQL test database.');

        $probe = $this->secondConnection();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = PaymentPromise::withoutEvents(fn () => PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]));

        $probe->beginTransaction();
        $probe->select('select id from payment_promises where id = ? for update', [$promise->id]);
        $this->assertLockTimeout(fn () => app(PromiseFulfillment::class)->fulfil($promise, [
            'amount' => 4000,
            'start_block' => 1,
            'end_block' => 1,
        ], silent: true));
        $probe->rollBack();

        $teacher = Teacher::create(['name' => 'Lock probe teacher']);
        $user->update(['teacher_id' => $teacher->id]);

        // The user lock is the empty-scope mutex: it serializes two first fixes
        // even when no matching settlement row exists yet.
        $probe->beginTransaction();
        $probe->select('select id from users where id = ? for update', [$user->id]);
        $this->assertLockTimeout(fn () => app(MutualSettlementService::class)->fix($user));
        $probe->rollBack();

        $act = app(MutualSettlementService::class)->fix($user);

        // Existing matching acts are locked too, so consumption/revision cannot
        // cross between the scope check and the superseding write.
        $probe->beginTransaction();
        $probe->select('select id from mutual_settlements where id = ? for update', [$act->id]);
        $this->assertLockTimeout(fn () => app(MutualSettlementService::class)->fix($user));
        $probe->rollBack();

        $this->assertSame(1, MutualSettlement::query()->where('user_id', $user->id)->count());
    }

    private function secondConnection(): Connection
    {
        $default = config('database.default');
        config(['database.connections.mysql_lock_probe' => config("database.connections.{$default}")]);
        DB::purge('mysql_lock_probe');

        return $this->probe = DB::connection('mysql_lock_probe');
    }

    private function assertLockTimeout(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the second writer to time out on an InnoDB row lock.');
        } catch (QueryException $exception) {
            $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1205, 1213]);
        }
    }

    protected function tearDown(): void
    {
        while ($this->probe?->transactionLevel() > 0) {
            $this->probe->rollBack();
        }

        DB::disconnect('mysql_lock_probe');
        parent::tearDown();
    }
}
