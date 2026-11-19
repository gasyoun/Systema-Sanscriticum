<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

use App\Models\SchoolRoleQuota;
use App\Models\SchoolRoleQuotaReservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * H6174: слой хранения квотной машины — счётчики полос 60/20/20 и audit-trail
 * резерваций (school_role_quotas / school_role_quota_reservations).
 *
 * Concurrent reservation: удержание токенов — атомарный условный UPDATE в
 * транзакции (MySQL/SQLite); release возвращает токены в полосу, commit
 * списывает фактический расход (факт < резерва — излишек возвращается).
 * Один request_id — один side-effect (уник-индекс, гонка = replay).
 *
 * Поля реестров — только счётчики и идентификаторы, персональных данных нет.
 */
final class QuotaReservationStore
{
    public function findReservation(int $reservationId): ?SchoolRoleQuotaReservation
    {
        return SchoolRoleQuotaReservation::query()->find($reservationId);
    }

    public function findReservationByRequestId(string $requestId): ?SchoolRoleQuotaReservation
    {
        return SchoolRoleQuotaReservation::query()->where('request_id', $requestId)->first();
    }

    /**
     * Счётчики полос роли на окно (0 — строки ещё нет).
     *
     * @return array{committed: array<string, int>, reserved: array<string, int>}
     */
    public function laneCounters(string $roleId, string $windowKey): array
    {
        $row = SchoolRoleQuota::query()
            ->where('role_id', $roleId)
            ->where('window_key', $windowKey)
            ->first();

        $committed = [];
        $reserved = [];

        foreach (QuotaLane::cases() as $lane) {
            $committed[$lane->value] = $row === null ? 0 : (int) $row->{$lane->committedColumn()};
            $reserved[$lane->value] = $row === null ? 0 : (int) $row->{$lane->reservedColumn()};
        }

        return ['committed' => $committed, 'reserved' => $reserved];
    }

    /**
     * Атомарное удержание + создание резервации (одна транзакция).
     *
     * @return array{reserved_id: ?int, replay_id: ?int} reserved_id = новая
     *                                                   резервация; оба null = потолок полосы (60/20/20) не пропустил;
     *                                                   replay_id = повтор того же request_id (один side-effect).
     */
    public function holdAndCreate(
        string $roleId,
        string $windowKey,
        QuotaLane $lane,
        int $units,
        int $laneCap,
        string $requestId,
        bool $isExperiment,
    ): array {
        $laneCommitted = $lane->committedColumn();
        $laneReserved = $lane->reservedColumn();

        try {
            $reservedId = DB::transaction(function () use (
                $roleId,
                $windowKey,
                $lane,
                $units,
                $laneCap,
                $requestId,
                $isExperiment,
                $laneCommitted,
                $laneReserved,
            ): ?int {
                SchoolRoleQuota::query()->firstOrCreate([
                    'role_id' => $roleId,
                    'window_key' => $windowKey,
                ]);

                $affected = SchoolRoleQuota::query()
                    ->where('role_id', $roleId)
                    ->where('window_key', $windowKey)
                    ->whereRaw(
                        "{$laneCommitted} + {$laneReserved} + ? <= ?",
                        [$units, $laneCap],
                    )
                    ->update([
                        $laneReserved => DB::raw("{$laneReserved} + {$units}"),
                        'updated_at' => Carbon::now(),
                    ]);

                if ($affected === 0) {
                    return null;
                }

                return (int) SchoolRoleQuotaReservation::create([
                    'request_id' => $requestId,
                    'role_id' => $roleId,
                    'window_key' => $windowKey,
                    'lane' => $lane->value,
                    'is_experiment' => $isExperiment,
                    'units' => $units,
                    'status' => SchoolRoleQuotaReservation::STATUS_RESERVED,
                ])->id;
            });
        } catch (QueryException $e) {
            $existing = $this->findReservationByRequestId($requestId);

            if ($existing !== null) {
                return ['reserved_id' => null, 'replay_id' => (int) $existing->id];
            }

            throw $e;
        }

        return $reservedId === null
            ? ['reserved_id' => null, 'replay_id' => null]
            : ['reserved_id' => $reservedId, 'replay_id' => null];
    }

    /** Списание факта по резервации: committed += факт, reserved -= удержание. */
    public function commitReservation(SchoolRoleQuotaReservation $reservation, int $actualUnits): void
    {
        $lane = QuotaLane::from((string) $reservation->lane);
        $laneCommitted = $lane->committedColumn();
        $laneReserved = $lane->reservedColumn();
        $held = (int) $reservation->units;

        DB::transaction(function () use ($reservation, $laneCommitted, $laneReserved, $held, $actualUnits): void {
            SchoolRoleQuota::query()
                ->where('role_id', $reservation->role_id)
                ->where('window_key', $reservation->window_key)
                ->update([
                    $laneCommitted => DB::raw("{$laneCommitted} + {$actualUnits}"),
                    $laneReserved => DB::raw("{$laneReserved} - {$held}"),
                    'updated_at' => Carbon::now(),
                ]);

            $reservation->forceFill([
                'status' => SchoolRoleQuotaReservation::STATUS_COMMITTED,
                'consumed_units' => $actualUnits,
            ])->save();
        });
    }

    /** Возврат удержания без списания: reserved -= удержание, статус released. */
    public function releaseReservation(SchoolRoleQuotaReservation $reservation): void
    {
        $lane = QuotaLane::from((string) $reservation->lane);
        $laneReserved = $lane->reservedColumn();
        $held = (int) $reservation->units;

        DB::transaction(function () use ($reservation, $laneReserved, $held): void {
            SchoolRoleQuota::query()
                ->where('role_id', $reservation->role_id)
                ->where('window_key', $reservation->window_key)
                ->update([
                    $laneReserved => DB::raw("{$laneReserved} - {$held}"),
                    'updated_at' => Carbon::now(),
                ]);

            $reservation->forceFill([
                'status' => SchoolRoleQuotaReservation::STATUS_RELEASED,
            ])->save();
        });
    }
}
