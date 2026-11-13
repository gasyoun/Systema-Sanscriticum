<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Models\SchoolRoleQuota;
use App\Models\SchoolRoleQuotaReservation;
use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\QuotaVerdict;

/**
 * H6174: сценарии реестра — concurrent reservation/release/commit,
 * идемпотентность (один side-effect), статусные ограничения, ПИИ-сентинел.
 * Методы исполняются классом H6174HermesAcceptanceTest.
 */
trait H6174QuotaLedgerScenarios
{
    /** 4. Concurrent reservation: удержание ёмкости до release, commit с возвратом излишка. */
    public function test_concurrent_reservation_holds_capacity_until_release(): void
    {
        // A и B — одновременные держатели резерва.
        $a = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 400, 'conc-a');
        $b = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 200, 'conc-b');
        $this->assertTrue($a->allowed);
        $this->assertTrue($b->allowed);

        // C не проходит, пока A+B держат потолок 600.
        $this->assertSame(
            QuotaVerdict::CODE_LANE_EXHAUSTED,
            $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 1, 'conc-c')->code,
        );

        // Release освобождает ёмкость немедленно.
        $this->assertTrue($this->quota->release((int) $a->reservationId)->allowed);
        $c = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 1, 'conc-c2');
        $this->assertTrue($c->allowed);

        // Commit списывает факт (150 из 200 — излишек возвращается в полосу).
        $this->assertTrue($this->quota->commit((int) $b->reservationId, 150)->allowed);
        $status = $this->quota->status('faq-rag');
        $this->assertSame(150, $status->laneCommitted['routine']);
        $this->assertSame(1, $status->laneReserved['routine']);

        $this->assertTrue($this->quota->commit((int) $c->reservationId, 1)->allowed);
        $status = $this->quota->status('faq-rag');
        $this->assertSame(151, $status->laneCommitted['routine']);
        $this->assertSame(0, $status->laneReserved['routine']);
    }

    /** 5. Идемпотентность: повтор request_id / commit / release — один side-effect. */
    public function test_request_id_is_idempotent_one_side_effect(): void
    {
        $first = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 200, 'dup-1');
        $this->assertTrue($first->allowed);

        $replay = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 200, 'dup-1');
        $this->assertSame(QuotaVerdict::CODE_IDEMPOTENT_REPLAY, $replay->code);
        $this->assertSame($first->reservationId, $replay->reservationId);

        $status = $this->quota->status('faq-rag');
        $this->assertSame(200, $status->laneReserved['routine']);

        $this->assertTrue($this->quota->release((int) $first->reservationId)->allowed);
        $this->assertSame(
            QuotaVerdict::CODE_IDEMPOTENT_REPLAY,
            $this->quota->release((int) $first->reservationId)->code,
        );
        $this->assertSame(0, $this->quota->status('faq-rag')->laneReserved['routine']);

        $second = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 200, 'dup-2');
        $this->assertTrue($this->quota->commit((int) $second->reservationId, 200)->allowed);
        $this->assertSame(
            QuotaVerdict::CODE_IDEMPOTENT_REPLAY,
            $this->quota->commit((int) $second->reservationId, 200)->code,
        );
        $this->assertSame(200, $this->quota->status('faq-rag')->laneCommitted['routine']);
    }

    /** 6. Статусные охраны: commit по выпущенной резервации и release по закоммиченной — отказ. */
    public function test_release_and_commit_state_guards(): void
    {
        $committed = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 100, 'guard-1');
        $this->quota->commit((int) $committed->reservationId, 100);
        $this->assertSame(
            QuotaVerdict::CODE_ALREADY_COMMITTED,
            $this->quota->release((int) $committed->reservationId)->code,
        );

        $released = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 100, 'guard-2');
        $this->quota->release((int) $released->reservationId);
        $this->assertSame(
            QuotaVerdict::CODE_ALREADY_RELEASED,
            $this->quota->commit((int) $released->reservationId, 50)->code,
        );
    }

    /** 9. Позитив: успешный вызов списывает факт (80 из 100), излишек возвращается. */
    public function test_success_commits_actual_tokens_and_refunds_rest(): void
    {
        $llm = fn (): array => ['result' => 'ok-payload', 'tokens' => 80];

        $result = $this->quota->executeWithPolicy('faq-rag', QuotaLane::ROUTINE, 100, false, $llm, 'ok-task');

        $this->assertSame(QuotaVerdict::CODE_OK, $result['verdict']->code);
        $this->assertSame('ok-payload', $result['result']);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame(80, $result['spent']);

        $status = $this->quota->status('faq-rag');
        $this->assertSame(80, $status->laneCommitted['routine']);
        $this->assertSame(0, $status->laneReserved['routine']);
    }

    /** 12. ПИИ-сентинел не попадает в реестр квоты: только счётчики и идентификаторы. */
    public function test_pii_sentinel_never_reaches_quota_ledger(): void
    {
        $llm = fn (): array => ['result' => self::SENTINEL, 'tokens' => 50];

        $this->quota->executeWithPolicy('faq-rag', QuotaLane::ROUTINE, 50, false, $llm, 'pii-task');
        $this->spend(QuotaLane::CHECK, 10, 'pii-check');

        $expectedQuotaKeys = [
            'id', 'role_id', 'window_key', 'routine_committed', 'routine_reserved',
            'check_committed', 'check_reserved', 'reserve_committed', 'reserve_reserved',
            'created_at', 'updated_at',
        ];
        $expectedReservationKeys = [
            'id', 'request_id', 'role_id', 'window_key', 'lane', 'is_experiment',
            'units', 'status', 'consumed_units', 'created_at', 'updated_at',
        ];
        sort($expectedQuotaKeys);
        sort($expectedReservationKeys);

        foreach (SchoolRoleQuota::all() as $row) {
            $keys = array_keys($row->toArray());
            sort($keys);
            $this->assertSame($expectedQuotaKeys, $keys);
            $this->assertStringNotContainsString(self::SENTINEL, (string) json_encode($row->toArray(), JSON_UNESCAPED_UNICODE));
        }

        foreach (SchoolRoleQuotaReservation::all() as $row) {
            $keys = array_keys($row->toArray());
            sort($keys);
            $this->assertSame($expectedReservationKeys, $keys);
            $this->assertStringNotContainsString(self::SENTINEL, (string) json_encode($row->toArray(), JSON_UNESCAPED_UNICODE));
        }
    }
}
