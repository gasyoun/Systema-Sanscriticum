<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\MoneyReconException;
use App\Models\MoneyReconExceptionEvent;
use App\Models\MoneyReconRun;
use Illuminate\Support\Facades\DB;

/**
 * H5445 (P3): очередь исключений сверки.
 *
 * 1. Открытие идемпотентно по ключу: повтор того же доказательства только
 *    отмечает «видели в прогоне N» — ни второй строки, ни второго события,
 *    ни повторного алерта.
 * 2. Сверка сама исключение не закрывает никогда (D8: «не применять молча»):
 *    если условие пропало, исключение остаётся открытым и видно как
 *    «не видели в последнем прогоне», пока человек его не разрешит.
 * 3. Разрешение/отклонение — только с автором и основанием (D9/D17/D21);
 *    закрытое не переоткрывается: изменившееся доказательство даёт новый ключ.
 */
final class ExceptionQueue
{
    /**
     * @param  array{type: string, source: string, source_ref: string, evidence: array<string, mixed>, amount_kopecks?: ?int, currency?: ?string, user_id?: ?int}  $finding
     * @return array{0: MoneyReconException, 1: bool} исключение и признак «открыто сейчас»
     */
    public function open(array $finding, ?MoneyReconRun $run): array
    {
        if (! in_array($finding['type'], MoneyReconException::TYPES, true)) {
            throw new ReconInvariantViolation('recon: unknown exception type '.$finding['type']);
        }

        $key = self::key($finding);

        return DB::transaction(function () use ($finding, $run, $key): array {
            $existing = MoneyReconException::query()->where('exception_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($run !== null && $existing->state === MoneyReconException::OPEN && $existing->last_seen_run_id !== $run->id) {
                    $existing->last_seen_run_id = $run->id;
                    $existing->save();
                }

                return [$existing, false];
            }

            $e = MoneyReconException::query()->create([
                'exception_key' => $key,
                'type' => $finding['type'],
                'source' => $finding['source'],
                'source_ref' => $finding['source_ref'],
                'evidence' => $finding['evidence'],
                'amount_kopecks' => $finding['amount_kopecks'] ?? null,
                'currency' => $finding['currency'] ?? null,
                'user_id' => $finding['user_id'] ?? null,
                'state' => MoneyReconException::OPEN,
                'first_run_id' => $run?->id,
                'last_seen_run_id' => $run?->id,
            ]);
            MoneyReconExceptionEvent::query()->create([
                'exception_id' => $e->id,
                'event' => MoneyReconExceptionEvent::OPENED,
                'run_id' => $run?->id,
                'reason' => $finding['evidence']['detail'] ?? null,
                'evidence' => $finding['evidence'],
            ]);

            return [$e, true];
        });
    }

    /** Разрешить: деньги/доступ приведены в порядок вручную (основание обязательно). */
    public function resolve(MoneyReconException $e, int $actorId, string $reason, array $evidence = []): MoneyReconException
    {
        return $this->close($e, MoneyReconException::RESOLVED, MoneyReconExceptionEvent::RESOLVED, $actorId, $reason, $evidence);
    }

    /** Отклонить: исключение ложное (основание обязательно). */
    public function dismiss(MoneyReconException $e, int $actorId, string $reason, array $evidence = []): MoneyReconException
    {
        return $this->close($e, MoneyReconException::DISMISSED, MoneyReconExceptionEvent::DISMISSED, $actorId, $reason, $evidence);
    }

    public function note(MoneyReconException $e, int $actorId, string $reason, array $evidence = []): MoneyReconExceptionEvent
    {
        $this->requireReason($reason);

        return MoneyReconExceptionEvent::query()->create([
            'exception_id' => $e->id,
            'event' => MoneyReconExceptionEvent::NOTE,
            'actor_id' => $actorId,
            'reason' => $reason,
            'evidence' => $evidence ?: null,
        ]);
    }

    /**
     * Ключ = тип + источник + строка + хэш доказательства: то же доказательство
     * всегда даёт тот же ключ, изменившееся — новый.
     *
     * @param  array{type: string, source: string, source_ref: string, evidence: array<string, mixed>}  $finding
     */
    public static function key(array $finding): string
    {
        return $finding['type'].':'.$finding['source_ref'].':'.substr(hash('sha256', Canonical::json($finding['evidence'])), 0, 16);
    }

    private function close(MoneyReconException $e, string $state, string $event, int $actorId, string $reason, array $evidence): MoneyReconException
    {
        $this->requireReason($reason);

        return DB::transaction(function () use ($e, $state, $event, $actorId, $reason, $evidence): MoneyReconException {
            $fresh = MoneyReconException::query()->whereKey($e->id)->lockForUpdate()->firstOrFail();
            if ($fresh->state !== MoneyReconException::OPEN) {
                throw new ReconInvariantViolation("recon: exception #{$fresh->id} is already {$fresh->state}");
            }
            $fresh->state = $state;
            $fresh->resolved_at = now();
            $fresh->resolved_by = $actorId;
            $fresh->save();
            MoneyReconExceptionEvent::query()->create([
                'exception_id' => $fresh->id,
                'event' => $event,
                'actor_id' => $actorId,
                'reason' => $reason,
                'evidence' => $evidence ?: null,
            ]);

            return $fresh;
        });
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new ReconInvariantViolation('recon: a manual action needs a reason');
        }
    }
}
