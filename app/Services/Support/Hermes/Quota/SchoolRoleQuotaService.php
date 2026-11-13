<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

use App\Models\SchoolRoleQuotaReservation;
use App\Services\Support\StudentAgent\AgentBudget;
use Illuminate\Support\Carbon;

/**
 * H6174 (E026, class security): подписочная квота по школьным ролям —
 * квотная машина Q7 (per-role лимиты; killgate-слой H5867 — отдельный общий
 * слой и здесь не дублируется).
 *
 * Принятая схема (docs/SCHOOL_AGENTS_90_DAY_STRATEGY_2026-10-04.md, §4):
 *   - 60/20/20 внутри выделенной квоты роли: штатная работа / проверки /
 *     резерв — жёсткие потолки полос (QuotaLane);
 *   - пороги 79/80/99/100 процентов расхода: 79/99 — предупреждения, на 80% —
 *     стоп экспериментов, на 100% — ноль LLM-вызовов;
 *   - concurrent reservation и release: атомарное удержание токенов
 *     (QuotaReservationStore), release возвращает токены в полосу, commit
 *     списывает фактический расход;
 *   - максимум два повтора на временную ошибку (TransientRetryLimiter),
 *     без рекурсивных агентных циклов;
 *   - платного fallback нет: любой отказ — очередь человеку
 *     (QuotaVerdict::FALLBACK_NONE), платных маршрутов у машины нет.
 *
 * Идемпотентность: повтор того же request_id не создаёт второго эффекта.
 * Канонические role ID — из ростера (Uprava
 * docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md); машина не создаёт
 * новых ID. Роли 8/39/42 (ИСКЛ) блокируются при любой попытке.
 *
 * Неизмеренная квота (allowance TBD) — log-only (прецедент H5867). Дефолт
 * enabled=false — никакого production-включения; машина не подключена ни к
 * одному прод-пути. Персональных данных в машине нет: реестр хранит только
 * счётчики, каноник-слаг роли, окно, полосу и технический ключ request_id.
 */
final class SchoolRoleQuotaService
{
    /** Формат канонического role ID ростера (слаг вида `faq-rag`). */
    private const ROLE_ID_PATTERN = '/^[a-z][a-z0-9-]{1,63}$/';

    private QuotaReservationStore $store;

    public function __construct(private readonly ?string $windowKey = null)
    {
        $this->store = new QuotaReservationStore;
    }

    /** Окно учёта (месяц по умолчанию) — «лимиты по ролям и периодам». */
    public function windowKey(): string
    {
        return $this->windowKey ?? Carbon::now()->format('Y-m');
    }

    /** Порог в процентах из конфига (см. QuotaConfig). */
    public static function threshold(string $key): int
    {
        return QuotaConfig::threshold($key);
    }

    public function enabled(): bool
    {
        return QuotaConfig::enabled();
    }

    /** Квота роли в токенах; null = неизмерена (TBD) ⇒ log-only. */
    public function allowance(string $roleId): ?int
    {
        return QuotaConfig::allowance($roleId);
    }

    /**
     * Снимок квоты роли: счётчики полос, расход и режим по порогам
     * 79/80/99/100. Read-only, не зависит от enabled.
     */
    public function status(string $roleId): QuotaStatus
    {
        $counters = $this->store->laneCounters($roleId, $this->windowKey());

        return new QuotaStatus(
            roleId: $roleId,
            windowKey: $this->windowKey(),
            allowance: $this->allowance($roleId),
            committedTokens: array_sum($counters['committed']),
            reservedTokens: array_sum($counters['reserved']),
            laneCommitted: $counters['committed'],
            laneReserved: $counters['reserved'],
        );
    }

    /**
     * Резервирование токенов под будущий LLM-вызов (concurrent reservation).
     *
     * Одновременные резервы удерживают ёмкость полосы до release/commit.
     * Порядок проверок: валидность → идемпотентность → TBD → пороги 100/80 →
     * потолок полосы 60/20/20.
     */
    public function reserve(
        string $roleId,
        QuotaLane $lane,
        int $units,
        string $requestId,
        bool $isExperiment = false,
    ): QuotaVerdict {
        if (! $this->enabled()) {
            return QuotaVerdict::allowed(
                QuotaVerdict::STATUS_DISABLED,
                QuotaVerdict::CODE_DISABLED,
                'quota machine disabled (default OFF — no production enablement)',
            );
        }

        if (preg_match(self::ROLE_ID_PATTERN, $roleId) !== 1) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_INVALID_ROLE_ID,
                'role id '.$roleId.' is not a canonical roster slug (no new canonical IDs)',
            );
        }

        if (in_array($roleId, QuotaConfig::excludedRoleIds(), true)) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_EXCLUDED_ROLE,
                'role '.$roleId.' is excluded (roster IDs 8/39/42, ИСКЛ) — any activation attempt is blocked',
            );
        }

        if ($units <= 0) {
            return QuotaVerdict::denied(QuotaVerdict::CODE_INVALID_UNITS, 'reservation units must be positive');
        }

        $existing = $this->store->findReservationByRequestId($requestId);

        if ($existing !== null) {
            return QuotaVerdict::allowed(
                QuotaVerdict::STATUS_OK,
                QuotaVerdict::CODE_IDEMPOTENT_REPLAY,
                'request already reserved — one side-effect per request_id',
                (int) $existing->id,
            );
        }

        $allowance = $this->allowance($roleId);

        if ($allowance === null) {
            return QuotaVerdict::allowed(
                QuotaVerdict::STATUS_UNMEASURED,
                QuotaVerdict::CODE_UNMEASURED,
                'allowance TBD (unmeasured) — log-only, nothing is reserved or killed (H5867 precedent)',
            );
        }

        $status = $this->status($roleId);

        // Порог 100%: на 100 — ноль LLM-вызовов; вызов не может перешагнуть квоту.
        if (
            $status->crossesPct(self::threshold('hard_stop_pct'))
            || $status->usedTokens() + $units > $allowance
        ) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_HARD_STOP,
                sprintf(
                    'role %s window %s: %d used + %d requested exceeds allowance %d — zero LLM calls at 100%%',
                    $roleId,
                    $this->windowKey(),
                    $status->usedTokens(),
                    $units,
                    $allowance,
                ),
            );
        }

        // Порог 80%: на 80 — стоп экспериментов (по прогнозу расхода).
        if ($isExperiment) {
            $projectedPctNumerator = ($status->usedTokens() + $units) * 100;

            if ($projectedPctNumerator >= self::threshold('experiments_stop_pct') * $allowance) {
                return QuotaVerdict::denied(
                    QuotaVerdict::CODE_EXPERIMENTS_STOPPED,
                    sprintf(
                        'role %s window %s: experiment would cross %d%% of allowance %d — experiments stopped',
                        $roleId,
                        $this->windowKey(),
                        self::threshold('experiments_stop_pct'),
                        $allowance,
                    ),
                );
            }
        }

        // Потолок полосы 60/20/20 + удержание (атомарно, см. store).
        $laneCap = $lane->cap($allowance);
        $held = $this->store->holdAndCreate(
            $roleId,
            $this->windowKey(),
            $lane,
            $units,
            $laneCap,
            $requestId,
            $isExperiment,
        );

        if ($held['replay_id'] !== null) {
            return QuotaVerdict::allowed(
                QuotaVerdict::STATUS_OK,
                QuotaVerdict::CODE_IDEMPOTENT_REPLAY,
                'request already reserved — one side-effect per request_id',
                $held['replay_id'],
            );
        }

        if ($held['reserved_id'] === null) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_LANE_EXHAUSTED,
                sprintf(
                    'role %s window %s: lane %s cap %d would be exceeded (%d committed + %d reserved + %d requested)',
                    $roleId,
                    $this->windowKey(),
                    $lane->value,
                    $laneCap,
                    $status->laneCommitted[$lane->value],
                    $status->laneReserved[$lane->value],
                    $units,
                ),
            );
        }

        return QuotaVerdict::allowed(
            QuotaVerdict::STATUS_OK,
            QuotaVerdict::CODE_OK,
            sprintf('reserved %d tokens on lane %s (cap %d)', $units, $lane->value, $laneCap),
            $held['reserved_id'],
        );
    }

    /**
     * Списание фактического расхода по резервации (несгораемый остаток:
     * факт < резерва — разница возвращается в полосу).
     */
    public function commit(int $reservationId, ?int $actualUnits = null): QuotaVerdict
    {
        $reservation = $this->store->findReservation($reservationId);

        if ($reservation === null) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_NO_RESERVATION,
                'reservation '.$reservationId.' does not exist',
            );
        }

        if ($reservation->status === SchoolRoleQuotaReservation::STATUS_COMMITTED) {
            return QuotaVerdict::allowed(
                QuotaVerdict::STATUS_OK,
                QuotaVerdict::CODE_IDEMPOTENT_REPLAY,
                'reservation already committed — one side-effect per reservation',
                $reservationId,
            );
        }

        if ($reservation->status === SchoolRoleQuotaReservation::STATUS_RELEASED) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_ALREADY_RELEASED,
                'reservation '.$reservationId.' is already released',
            );
        }

        $actual = $actualUnits ?? (int) $reservation->units;

        if ($actual < 0) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_INVALID_ACTUAL_UNITS,
                'actual units must be >= 0',
            );
        }

        $this->store->commitReservation($reservation, $actual);

        return QuotaVerdict::allowed(
            QuotaVerdict::STATUS_OK,
            QuotaVerdict::CODE_OK,
            sprintf('committed %d tokens (held %d) on lane %s', $actual, (int) $reservation->units, $reservation->lane),
            $reservationId,
        );
    }

    /**
     * Возврат резерва без списания (вызов не состоялся). Повторный release —
     * идемпотентный no-op.
     */
    public function release(int $reservationId): QuotaVerdict
    {
        $reservation = $this->store->findReservation($reservationId);

        if ($reservation === null) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_NO_RESERVATION,
                'reservation '.$reservationId.' does not exist',
            );
        }

        if ($reservation->status === SchoolRoleQuotaReservation::STATUS_RELEASED) {
            return QuotaVerdict::allowed(
                QuotaVerdict::STATUS_OK,
                QuotaVerdict::CODE_IDEMPOTENT_REPLAY,
                'reservation already released — one side-effect per reservation',
                $reservationId,
            );
        }

        if ($reservation->status === SchoolRoleQuotaReservation::STATUS_COMMITTED) {
            return QuotaVerdict::denied(
                QuotaVerdict::CODE_ALREADY_COMMITTED,
                'reservation '.$reservationId.' is already committed',
            );
        }

        $this->store->releaseReservation($reservation);

        return QuotaVerdict::allowed(
            QuotaVerdict::STATUS_OK,
            QuotaVerdict::CODE_OK,
            sprintf('released %d tokens on lane %s', (int) $reservation->units, $reservation->lane),
            $reservationId,
        );
    }

    /**
     * Полный цикл вызова под квотой: reserve → LLM → commit/release.
     *
     * $llmCall(): array{result: mixed, tokens: int} — единственный канал
     * вызова; сама машина никаких иных вызовов (в частности платных) не
     * делает — платного fallback нет. Временная ошибка (TransientCallException)
     * повторяется ≤ max_retries (итеративно); на исчерпании — очередь
     * человеку. При отказе квоты $llmCall не вызывается вовсе.
     *
     * @param  callable(): array{result: mixed, tokens: int}  $llmCall
     * @return array{verdict: QuotaVerdict, result: mixed, attempts: int, spent: ?int}
     */
    public function executeWithPolicy(
        string $roleId,
        QuotaLane $lane,
        int $units,
        bool $isExperiment,
        callable $llmCall,
        string $requestId,
    ): array {
        $limiter = TransientRetryLimiter::fromConfig();
        $attempt = 0;
        $retries = 0;

        while (true) {
            $attempt++;
            $attemptRequestId = $retries === 0 ? $requestId : $requestId.'#retry'.$retries;

            $verdict = $this->reserve($roleId, $lane, $units, $attemptRequestId, $isExperiment);

            if (! $verdict->allowed) {
                // Отказ квоты: ноль LLM-вызовов, платного fallback нет — очередь человеку.
                return [
                    'verdict' => $verdict,
                    'result' => null,
                    'attempts' => $attempt - 1,
                    'spent' => null,
                ];
            }

            try {
                $payload = $llmCall();
                $tokens = (int) ($payload['tokens'] ?? $units);

                if ($verdict->reservationId !== null) {
                    $this->commit($verdict->reservationId, $tokens);
                }

                return [
                    'verdict' => QuotaVerdict::allowed(
                        QuotaVerdict::STATUS_OK,
                        QuotaVerdict::CODE_OK,
                        'call completed within quota',
                        $verdict->reservationId,
                    ),
                    'result' => $payload['result'] ?? null,
                    'attempts' => $attempt,
                    'spent' => $tokens,
                ];
            } catch (\Throwable $e) {
                if ($verdict->reservationId !== null) {
                    $this->release($verdict->reservationId);
                }

                $transient = $limiter->isTransient($e);

                if ($limiter->shouldRetry($retries, $transient)) {
                    $retries++;

                    continue;
                }

                return [
                    'verdict' => QuotaVerdict::denied(
                        $transient ? QuotaVerdict::CODE_RETRIES_EXHAUSTED : QuotaVerdict::CODE_TASK_FAILED,
                        $transient
                            ? sprintf('transient failure after %d attempt(s) — max %d retries exhausted, queued for human', $attempt, $limiter->maxRetries())
                            : sprintf('non-transient failure after %d attempt(s) — not retried, queued for human', $attempt),
                    ),
                    'result' => null,
                    'attempts' => $attempt,
                    'spent' => null,
                ];
            }
        }
    }

    /**
     * Мост к существующему снимку бюджета (AgentBudget, H3231) — не второй
     * механизм: машина отдаёт измеренные оси tokens/max_tokens.
     *
     * @return array<string, mixed>
     */
    public function budgetSnapshot(string $roleId): array
    {
        $status = $this->status($roleId);

        return AgentBudget::snapshot(
            tokens: $status->usedTokens(),
            maxTokens: $status->allowance,
        );
    }
}
