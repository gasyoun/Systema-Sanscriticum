<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Models\Course;
use App\Models\Group;
use App\Models\MoneyReconException;
use App\Models\MoneyReconExceptionEvent;
use App\Models\MoneyReconRun;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reconciliation\DailyReconciler;
use App\Services\Reconciliation\EvidenceCollector;
use App\Services\Reconciliation\ExceptionQueue;
use App\Services\Reconciliation\ReconInvariantViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5445 (P3): ежедневная сверка — классы строк, типизированные исключения,
 * повтор без эффекта, дрейф, отсутствующие источники, жизненный цикл очереди.
 * Только синтетические данные; платежи вставляются без событий модели.
 */
class DailyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-09-23';

    private Course $course;

    private Course $grouplessCourse;

    private User $student;

    private User $admin;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 05:00:00');
        config(['features.money_ledger_core' => false, 'features.money_refund_access_rules' => false]);

        $this->course = Course::factory()->create();
        $group = Group::factory()->create();
        $this->course->groups()->attach($group);
        $this->grouplessCourse = Course::factory()->create();
        $this->student = User::factory()->create();
        $this->student->groups()->attach($group);
        $this->admin = User::factory()->create();

        $this->ids['matched'] = $this->pay(['amount' => '8000.00', 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 2, 'transaction_id' => 'T-OK'])->id;
        $this->ids['full_tariff'] = $this->pay(['amount' => '12000.00', 'tariff' => 'full', 'transaction_id' => 'T-FULL'])->id;
        $this->ids['dup_a'] = $this->pay(['amount' => '4000.00', 'tariff' => 'block_3', 'transaction_id' => 'T-DUP'])->id;
        $this->ids['dup_b'] = $this->pay(['amount' => '4000.00', 'tariff' => 'block_3', 'transaction_id' => 'T-DUP',
            'user_id' => User::factory()->create()->id])->id;
        $this->ids['beyond'] = $this->pay(['amount' => '4000.00', 'tariff' => 'block_5', 'provider' => Payment::PROVIDER_PAYPAL,
            'foreign_amount' => '30.00', 'foreign_currency' => 'EUR',
            'claim_meta' => ['txn' => 'PP-1', 'amount_check' => ['verdict' => 'beyond_5']]])->id;
        $this->ids['expired'] = $this->pay(['amount' => '4000.00', 'tariff' => 'block_6', 'payment_link_expires_at' => self::DAY.' 09:00:00'])->id;
        $this->ids['groupless'] = $this->pay(['amount' => '4000.00', 'tariff' => 'block_1', 'course_id' => $this->grouplessCourse->id])->id;
        $this->ids['no_course'] = $this->pay(['amount' => '500.00', 'tariff' => 'block_1', 'course_id' => null])->id;
        $this->ids['refund_partial'] = $this->pay(['amount' => '-1000.00', 'tariff' => 'Расход', 'refund_of_payment_id' => $this->ids['matched']])->id;
        $this->ids['expense'] = $this->pay(['amount' => '-300.00', 'tariff' => 'Расход'])->id;
        $this->ids['sibling'] = $this->pay(['amount' => '0.00', 'tariff' => 'block_2', 'transaction_id' => 'access_grant_#'.$this->ids['matched']])->id;

        DB::table('payment_webhook_events')->insert([
            ['provider' => 'tochka', 'payment_id' => null, 'event_hash' => str_repeat('a', 64), 'bank_status' => 'APPROVED', 'reported_amount' => '777.00', 'decision' => 'unmatched', 'created_at' => self::DAY.' 11:00:00'],
            ['provider' => 'tochka', 'payment_id' => $this->ids['matched'], 'event_hash' => str_repeat('b', 64), 'bank_status' => 'APPROVED', 'reported_amount' => '8000.00', 'decision' => 'applied', 'created_at' => self::DAY.' 10:00:00'],
        ]);
    }

    private function pay(array $attrs): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'first_paid_at' => self::DAY.' 12:00:00',
            'created_at' => self::DAY.' 12:00:00',
        ], $attrs)));
    }

    private function reconcile(bool $persist = true): array
    {
        return app(DailyReconciler::class)->run(CarbonImmutable::parse(self::DAY), false, $persist, 'manual');
    }

    private function exceptionFor(string $ref, string $type): ?MoneyReconException
    {
        return MoneyReconException::query()->where('source_ref', $ref)->where('type', $type)->first();
    }

    public function test_every_row_is_classified_once_and_totals_reconcile(): void
    {
        $r = $this->reconcile(false);

        $this->assertSame(DailyReconciler::OUTCOME_DRY, $r['outcome']);
        // 9 оплат/возвратов + 2 вебхука; расход без связи и нулевой sibling — вне сверки, но посчитаны.
        $this->assertSame(11, $r['totals']['rows']);
        $this->assertSame(['zero_amount_access_only' => 1, 'outflow_out_of_scope' => 1, 'pending_without_exception' => 0], $r['totals']['excluded']);
        $this->assertTrue($r['identity_ok']);
        $this->assertSame(11, array_sum(array_column($r['totals']['classes'], 'rows')));

        $classes = $r['totals']['classes'];
        $this->assertSame(1, $classes['unallocated']['rows']);
        $this->assertSame(1_200_000, $classes['unallocated']['kopecks']);
        $this->assertSame(2, $classes['exception:reused_evidence']['rows']);
        $this->assertSame(1, $classes['exception:currency_amount_mismatch']['rows']);
        $this->assertSame(1, $classes['exception:expired_terms']['rows']);
        $this->assertSame(1, $classes['exception:impossible_access']['rows']);
        $this->assertSame(2, $classes['exception:unknown_purpose']['rows']); // оплата без курса + неопознанный вебхук
        $this->assertSame(1, $classes['exception:refund_without_blocks']['rows']);
        $this->assertSame(2, $classes['matched']['rows']); // оплата блоков + применённый вебхук

        $this->assertSame(0, MoneyReconRun::query()->count(), 'dry run writes nothing');
        $this->assertSame(0, MoneyReconException::query()->count());
    }

    public function test_missing_source_is_loud_and_never_zero(): void
    {
        $r = $this->reconcile(false);

        $this->assertSame(MoneyReconRun::INCOMPLETE, $r['status']);
        $this->assertSame(['bank_statement', 'ledger'], $r['missing_sources']);
        $this->assertSame('missing', $r['sources']['bank_statement']['status']);
        $this->assertNotEmpty($r['sources']['bank_statement']['note']);
        $this->assertSame('dark', $r['sources']['ledger']['status']);
        $this->assertSame('legacy', $r['sources']['payout_packages']['status']);
        $this->assertArrayNotHasKey('bank_statement', $r['totals']['channels'], 'a missing source contributes no zero row');
    }

    public function test_persisted_rerun_with_identical_evidence_has_no_effect(): void
    {
        $first = $this->reconcile();
        $this->assertSame(DailyReconciler::OUTCOME_RECORDED, $first['outcome']);
        $exceptions = MoneyReconException::query()->count();
        $events = MoneyReconExceptionEvent::query()->count();
        $this->assertGreaterThan(0, $exceptions);
        $this->assertSame($exceptions, $first['new_exceptions']);
        $this->assertSame($exceptions, $events, 'one "opened" event per exception');

        $again = $this->reconcile();

        $this->assertSame(DailyReconciler::OUTCOME_REPLAY, $again['outcome']);
        $this->assertSame($first['run_id'], $again['run_id']);
        $this->assertSame($first['totals_checksum'], $again['totals_checksum']);
        $this->assertSame(0, $again['new_exceptions'], 'no alert storm on replay');
        $this->assertSame(1, MoneyReconRun::query()->count());
        $this->assertSame($exceptions, MoneyReconException::query()->count());
        $this->assertSame($events, MoneyReconExceptionEvent::query()->count());
    }

    public function test_new_evidence_opens_only_new_exceptions(): void
    {
        $first = $this->reconcile();
        $before = MoneyReconException::query()->count();

        $this->pay(['amount' => '999.00', 'tariff' => 'block_1', 'course_id' => null, 'transaction_id' => 'T-NEW']);
        $second = $this->reconcile();

        $this->assertSame(DailyReconciler::OUTCOME_RECORDED, $second['outcome']);
        $this->assertNotSame($first['input_fingerprint'], $second['input_fingerprint']);
        $this->assertSame(1, $second['new_exceptions']);
        $this->assertSame(['unknown_purpose' => 1], $second['new_exception_types']);
        $this->assertSame($before + 1, MoneyReconException::query()->count());
        // Старые исключения помечены «видели во втором прогоне».
        $this->assertSame($second['run_id'], $this->exceptionFor('payment:'.$this->ids['dup_a'], MoneyReconException::REUSED_EVIDENCE)->last_seen_run_id);
    }

    public function test_block_split_and_import_placeholder_are_not_reused_evidence(): void
    {
        // Прод 24-09: 2045 из 2047 «повторов» были меткой импорта у 182 студентов,
        // ещё 2 — одна оплата одного студента, разложенная на два блока.
        $split = [];
        foreach (['block_7', 'block_8', 'block_9'] as $tariff) {
            $split[] = $this->pay(['amount' => '4000.00', 'tariff' => $tariff, 'transaction_id' => 'T-SPLIT'])->id;
        }
        $label = 'Мульти-оплата (Блоки 1-4)';
        $imported = [
            $this->pay(['amount' => '4000.00', 'tariff' => 'block_10', 'transaction_id' => $label])->id,
            $this->pay(['amount' => '4000.00', 'tariff' => 'block_10', 'transaction_id' => $label, 'user_id' => User::factory()->create()->id])->id,
        ];
        $twice = [
            $this->pay(['amount' => '4000.00', 'tariff' => 'block_11', 'transaction_id' => 'T-TWICE'])->id,
            $this->pay(['amount' => '4000.00', 'tariff' => 'block_11', 'transaction_id' => 'T-TWICE'])->id,
        ];

        $this->reconcile();

        foreach ([...$split, ...$imported] as $id) {
            $this->assertNull($this->exceptionFor('payment:'.$id, MoneyReconException::REUSED_EVIDENCE), "payment:$id");
        }
        $this->assertSame([], EvidenceCollector::evidenceKeys(Payment::find($imported[0])));
        foreach ($twice as $id) {
            $this->assertNotNull($this->exceptionFor('payment:'.$id, MoneyReconException::REUSED_EVIDENCE), "payment:$id");
        }
    }

    public function test_expired_or_exhausted_promo_is_never_applied_silently(): void
    {
        $limited = DB::table('promo_codes')->insertGetId(['code' => 'ONE', 'value' => 10, 'usage_limit' => 1, 'expires_at' => '2026-12-31 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $expired = DB::table('promo_codes')->insertGetId(['code' => 'OLD', 'value' => 10, 'usage_limit' => null, 'expires_at' => '2026-09-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        // Первое погашение ONE — вчера (вне окна дня), второе — в окне.
        $this->pay(['amount' => '3600.00', 'tariff' => 'block_7', 'promo_code_id' => $limited, 'first_paid_at' => '2026-09-22 12:00:00', 'created_at' => '2026-09-22 12:00:00']);
        $second = $this->pay(['amount' => '3600.00', 'tariff' => 'block_8', 'promo_code_id' => $limited])->id;
        $late = $this->pay(['amount' => '3600.00', 'tariff' => 'block_9', 'promo_code_id' => $expired])->id;

        $this->reconcile();

        $e = $this->exceptionFor('payment:'.$second, MoneyReconException::EXPIRED_TERMS);
        $this->assertSame('promo_limit_exceeded', $e->evidence['detail']);
        $this->assertSame(2, $e->evidence['promo_rank']);
        $this->assertSame(1, $e->evidence['promo_usage_limit']);
        $this->assertSame('promo_expired_at_payment', $this->exceptionFor('payment:'.$late, MoneyReconException::EXPIRED_TERMS)->evidence['detail']);
    }

    public function test_concurrent_run_with_same_input_is_a_replay_not_a_crash(): void
    {
        $dry = $this->reconcile(false);
        // Конкурент записывает тот же вход сразу после нашей проверки «уже есть?».
        $raced = false;
        DB::listen(function ($q) use (&$raced, $dry): void {
            if ($raced || ! str_starts_with(strtolower($q->sql), 'select') || ! str_contains($q->sql, 'input_fingerprint')) {
                return;
            }
            $raced = true;
            DB::table('money_recon_runs')->insert([
                'business_date' => self::DAY, 'mode' => 'scheduled', 'status' => $dry['status'],
                'input_fingerprint' => $dry['input_fingerprint'], 'totals_checksum' => $dry['totals_checksum'],
                'sources' => '{}', 'totals' => '{}', 'classification' => '{}', 'exceptions_opened' => 0, 'created_at' => now(),
            ]);
        });

        $r = $this->reconcile();

        $this->assertTrue($raced);
        $this->assertSame(DailyReconciler::OUTCOME_REPLAY, $r['outcome']);
        $this->assertSame(0, $r['new_exceptions']);
        $this->assertSame(1, MoneyReconRun::query()->count());
        $this->assertSame(0, MoneyReconException::query()->count(), 'the losing run rolled back entirely');
    }

    public function test_checksum_drift_on_identical_input_fails_the_command(): void
    {
        $dry = $this->reconcile(false);
        MoneyReconRun::query()->create([
            'business_date' => self::DAY,
            'mode' => 'manual',
            'status' => $dry['status'],
            'input_fingerprint' => $dry['input_fingerprint'],
            'totals_checksum' => str_repeat('0', 64),
            'sources' => [],
            'totals' => [],
            'classification' => [],
        ]);

        $r = $this->reconcile();
        $this->assertSame(DailyReconciler::OUTCOME_DRIFT, $r['outcome']);

        $this->artisan('money:reconcile-daily', ['--date' => self::DAY, '--persist' => true])
            ->expectsOutputToContain('FAIL: дрейф')
            ->assertExitCode(1);
    }

    public function test_typed_exceptions_keep_their_evidence(): void
    {
        $this->reconcile();

        $dup = $this->exceptionFor('payment:'.$this->ids['dup_b'], MoneyReconException::REUSED_EVIDENCE);
        $this->assertNotNull($dup);
        $this->assertSame(['txn:T-DUP'], $dup->evidence['evidence_keys']);
        $this->assertSame(400_000, $dup->amount_kopecks);

        $this->assertSame('paid_beyond_5_percent', $this->exceptionFor('payment:'.$this->ids['beyond'], MoneyReconException::CURRENCY_AMOUNT_MISMATCH)->evidence['detail']);
        $this->assertSame('paid_after_link_expiry', $this->exceptionFor('payment:'.$this->ids['expired'], MoneyReconException::EXPIRED_TERMS)->evidence['detail']);
        $this->assertSame('course_without_access_groups', $this->exceptionFor('payment:'.$this->ids['groupless'], MoneyReconException::IMPOSSIBLE_ACCESS)->evidence['detail']);
        $this->assertSame('no_course', $this->exceptionFor('payment:'.$this->ids['no_course'], MoneyReconException::UNKNOWN_PURPOSE)->evidence['detail']);
        $this->assertSame('partial_refund_without_blocks', $this->exceptionFor('payment:'.$this->ids['refund_partial'], MoneyReconException::REFUND_WITHOUT_BLOCKS)->evidence['detail']);
        $unmatched = MoneyReconException::query()->where('source', 'webhook_journal')->firstOrFail();
        $this->assertSame(MoneyReconException::UNKNOWN_PURPOSE, $unmatched->type);
        $this->assertSame(77_700, $unmatched->amount_kopecks);
    }

    public function test_full_refund_with_access_retained_is_an_impossible_access_exception(): void
    {
        $this->pay(['amount' => '-8000.00', 'tariff' => 'Расход', 'refund_of_payment_id' => $this->ids['full_tariff'], 'start_block' => 1, 'end_block' => 1]);
        // Итого по full_tariff возвращено 8000 из 12000 — частично; дольём до полного.
        $this->pay(['amount' => '-4000.00', 'tariff' => 'Расход', 'refund_of_payment_id' => $this->ids['full_tariff']]);
        // Прочие оплаты курса у студента тоже «съедены»: оставляем доступ единственным держателем full_tariff.
        Payment::query()->whereKeyNot($this->ids['full_tariff'])->where('course_id', $this->course->id)->where('amount', '>', 0)
            ->each(fn (Payment $p) => Payment::withoutEvents(fn () => $p->forceFill(['status' => 'canceled'])->save()));

        $this->reconcile();

        $e = MoneyReconException::query()->where('type', MoneyReconException::IMPOSSIBLE_ACCESS)
            ->get()->first(fn ($e) => ($e->evidence['detail'] ?? null) === 'access_retained_after_full_refund');
        $this->assertNotNull($e, 'student kept group access after a full refund');
        $this->assertSame($this->ids['full_tariff'], $e->evidence['refund_of']);
    }

    public function test_exception_lifecycle_needs_actor_and_reason_and_never_reopens(): void
    {
        $this->reconcile();
        $queue = app(ExceptionQueue::class);
        $e = $this->exceptionFor('payment:'.$this->ids['no_course'], MoneyReconException::UNKNOWN_PURPOSE);

        try {
            $queue->resolve($e, $this->admin->id, '   ');
            $this->fail('resolution without reason must be refused');
        } catch (ReconInvariantViolation) {
        }
        $this->assertSame(MoneyReconException::OPEN, $e->fresh()->state);

        $queue->resolve($e, $this->admin->id, 'студент найден по чеку, привязан вручную');
        $e->refresh();
        $this->assertSame(MoneyReconException::RESOLVED, $e->state);
        $this->assertSame([MoneyReconExceptionEvent::OPENED, MoneyReconExceptionEvent::RESOLVED], $e->events->pluck('event')->all());
        $this->assertSame($this->admin->id, $e->events->last()->actor_id);

        // Условие всё ещё в данных; новый прогон (новое доказательство где-то ещё) не переоткрывает.
        $this->pay(['amount' => '1.00', 'tariff' => 'block_1', 'transaction_id' => 'T-X']);
        $this->reconcile();
        $this->assertSame(MoneyReconException::RESOLVED, $e->fresh()->state);

        $this->expectException(ReconInvariantViolation::class);
        $queue->dismiss($e->fresh(), $this->admin->id, 'повторное закрытие');
    }

    public function test_condition_disappearing_never_silently_resolves(): void
    {
        $this->reconcile();
        $e = $this->exceptionFor('payment:'.$this->ids['no_course'], MoneyReconException::UNKNOWN_PURPOSE);
        Payment::withoutEvents(fn () => Payment::query()->whereKey($this->ids['no_course'])->update(['course_id' => $this->course->id]));

        $second = $this->reconcile();

        $this->assertSame(MoneyReconException::OPEN, $e->fresh()->state);
        $this->assertNotSame($second['run_id'], $e->fresh()->last_seen_run_id, 'shows as not seen in the latest run');
    }

    public function test_journal_is_append_only_at_the_database(): void
    {
        $this->reconcile();
        $e = MoneyReconException::query()->firstOrFail();

        foreach ([
            fn () => DB::table('money_recon_runs')->update(['status' => 'complete']),
            fn () => DB::table('money_recon_runs')->delete(),
            fn () => DB::table('money_recon_exceptions')->where('id', $e->id)->update(['evidence' => '{}']),
            fn () => DB::table('money_recon_exceptions')->where('id', $e->id)->update(['type' => 'unknown_purpose', 'source_ref' => 'x']),
            fn () => DB::table('money_recon_exceptions')->where('id', $e->id)->update(['state' => 'resolved']), // без resolved_at
            fn () => DB::table('money_recon_exceptions')->where('id', $e->id)->delete(),
            fn () => DB::table('money_recon_exception_events')->update(['reason' => 'x']),
            fn () => DB::table('money_recon_exception_events')->delete(),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("raw write #{$i} must be refused by a trigger");
            } catch (QueryException $ex) {
                $this->assertStringContainsString('recon: ', $ex->getMessage());
            }
        }

        DB::table('money_recon_exceptions')->where('id', $e->id)->update(['state' => 'dismissed', 'resolved_at' => now()]);
        try {
            DB::table('money_recon_exceptions')->where('id', $e->id)->update(['state' => 'open', 'resolved_at' => null]);
            $this->fail('a closed exception must never reopen');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('recon: a closed exception never reopens', $ex->getMessage());
        }
    }

    public function test_manual_run_is_read_only_and_persist_writes_only_recon_tables(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'h5445');
        $this->artisan('money:reconcile-daily', ['--date' => self::DAY, '--json' => $path])->assertExitCode(0);
        $dry = json_decode((string) file_get_contents($path), true);
        $this->assertSame(['money_recon' => 0, 'other' => 0, 'other_sample' => []], $dry['writes']);
        $this->assertSame(0, MoneyReconRun::query()->count());

        $this->artisan('money:reconcile-daily', ['--date' => self::DAY, '--persist' => true, '--json' => $path])->assertExitCode(0);
        $persisted = json_decode((string) file_get_contents($path), true);
        unlink($path);
        $this->assertSame(0, $persisted['writes']['other']);
        $this->assertGreaterThan(0, $persisted['writes']['money_recon']);
        $this->assertSame($dry['totals_checksum'], $persisted['totals_checksum'], 'dry and persisted runs agree');
        $this->assertSame(1, MoneyReconRun::query()->count());
    }

    public function test_control_totals_are_reproducible(): void
    {
        $a = $this->reconcile(false);
        $b = $this->reconcile(false);

        $this->assertSame($a['input_fingerprint'], $b['input_fingerprint']);
        $this->assertSame($a['totals_checksum'], $b['totals_checksum']);
        $this->assertSame($a['totals'], $b['totals']);
    }

    public function test_scheduled_run_is_a_noop_while_flag_is_off(): void
    {
        config(['features.money_daily_reconciliation' => false]);

        $this->artisan('money:reconcile-daily', ['--persist' => true, '--scheduled' => true])
            ->expectsOutputToContain('no-op')
            ->assertExitCode(0);
        $this->assertSame(0, MoneyReconRun::query()->count());
    }

    public function test_scheduled_run_records_yesterday_when_flag_is_on(): void
    {
        config(['features.money_daily_reconciliation' => true, 'money_recon.ping_url' => '']);

        $this->artisan('money:reconcile-daily', ['--persist' => true, '--scheduled' => true, '--dry-alert' => true])->assertExitCode(0);

        $run = MoneyReconRun::query()->sole();
        $this->assertSame(self::DAY, $run->business_date->toDateString());
        $this->assertSame('scheduled', $run->mode);
        $this->assertSame(MoneyReconRun::INCOMPLETE, $run->status);
    }

    public function test_all_history_cannot_persist(): void
    {
        $this->artisan('money:reconcile-daily', ['--all' => true, '--persist' => true])->assertExitCode(2);
    }
}
