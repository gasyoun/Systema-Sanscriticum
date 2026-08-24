<?php

declare(strict_types=1);

namespace App\Services\Prana;

use App\Models\PranaTransaction;
use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PranaService
{
    /**
     * Начислить пользователю прану за конкретное действие.
     * Идемпотентно по (user_id, reason, source_type, source_id) — благодаря
     * уникальному индексу. Повторный вызов не начислит повторно.
     *
     * @return bool true если начисление прошло, false — если уже было.
     */
    public function award(
        User $user,
        string $reason,
        ?Model $source = null,
        ?int $amount = null,
        array $meta = [],
    ): bool {
        if (! PranaSettings::isActive()) {
            return false;
        }

        $amount ??= PranaSettings::reward($reason);

        if ($amount <= 0) {
            return false;
        }

        try {
            return DB::transaction(function () use ($user, $reason, $source, $amount, $meta) {
                PranaTransaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'meta' => $meta ?: null,
                ]);

                // Начисление пополняет и тратимый кошелёк, и накопительный
                // lifetime (для рангов — он тратами не уменьшается).
                DB::table('users')->where('id', $user->id)->increment('prana_balance', $amount);
                DB::table('users')->where('id', $user->id)->increment('lifetime_prana', $amount);

                return true;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Уже начисляли — это не ошибка.
            return false;
        } catch (\Throwable $e) {
            // Некоторые драйверы кидают QueryException с кодом 23000 вместо UniqueConstraintViolationException
            if ($e instanceof QueryException && $e->getCode() === '23000') {
                return false;
            }
            Log::warning('PranaService::award failed', [
                'user_id' => $user->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Списать прану. Возвращает количество фактически списанных единиц.
     * Бросает RuntimeException если баланс ниже запрошенной суммы.
     */
    public function spend(
        User $user,
        int $amount,
        string $reason,
        ?Model $source = null,
        array $meta = [],
    ): int {
        if ($amount <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($user, $amount, $reason, $source, $meta) {
            // Блокируем строку юзера на чтении, чтобы исключить race
            $current = (int) DB::table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->value('prana_balance');

            if ($current < $amount) {
                throw new \RuntimeException(
                    'Недостаточно праны: запрошено '.$amount.', доступно '.$current
                );
            }

            PranaTransaction::create([
                'user_id' => $user->id,
                'amount' => -$amount,
                'reason' => $reason,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'meta' => $meta ?: null,
            ]);

            DB::table('users')->where('id', $user->id)->decrement('prana_balance', $amount);

            return $amount;
        });
    }

    /**
     * Возврат ранее списанной праны (например, оплата сорвалась).
     * Идемпотентно по той же связке source — повторный вызов вернёт false.
     */
    public function refund(
        User $user,
        int $amount,
        string $reason,
        Model $source,
        array $meta = [],
    ): bool {
        if ($amount <= 0) {
            return false;
        }

        try {
            return DB::transaction(function () use ($user, $amount, $reason, $source, $meta) {
                PranaTransaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                    'meta' => $meta ?: null,
                ]);

                DB::table('users')->where('id', $user->id)->increment('prana_balance', $amount);

                return true;
            });
        } catch (UniqueConstraintViolationException $e) {
            return false;
        } catch (\Throwable $e) {
            if ($e instanceof QueryException && $e->getCode() === '23000') {
                return false;
            }
            Log::warning('PranaService::refund failed', [
                'user_id' => $user->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function balance(User $user): int
    {
        return (int) ($user->prana_balance ?? 0);
    }

    /**
     * P2P-перевод тратимой праны между студентами (подарок). Списывает у
     * отправителя, зачисляет получателю — оба только balance, lifetime/ранг НЕ
     * меняется (иначе ранги фармились бы перекидыванием). Соблюдает дневные лимиты.
     *
     * @throws \RuntimeException при ошибке валидации/баланса/лимита
     */
    public function transfer(User $from, User $to, int $amount): void
    {
        if ($amount <= 0) {
            throw new \RuntimeException('Сумма перевода должна быть положительной.');
        }
        if ($from->id === $to->id) {
            throw new \RuntimeException('Нельзя перевести прану самому себе.');
        }

        $dailyTotal = (int) config('prana.daily_p2p_limit', 30);
        $dailyPerUser = (int) config('prana.daily_p2p_per_user_limit', 10);

        DB::transaction(function () use ($from, $to, $amount, $dailyTotal, $dailyPerUser) {
            $current = (int) DB::table('users')->where('id', $from->id)->lockForUpdate()->value('prana_balance');
            if ($current < $amount) {
                throw new \RuntimeException("Недостаточно праны: запрошено {$amount}, доступно {$current}.");
            }

            // Дневные лимиты считаем по уже отправленному сегодня (p2p_sent — отрицательные).
            $sentToday = (int) abs(PranaTransaction::query()
                ->where('user_id', $from->id)
                ->where('reason', 'p2p_sent')
                ->whereDate('created_at', now()->toDateString())
                ->sum('amount'));
            if ($sentToday + $amount > $dailyTotal) {
                throw new \RuntimeException("Дневной лимит перевода — {$dailyTotal} праны.");
            }

            $sentToSameToday = (int) abs(PranaTransaction::query()
                ->where('user_id', $from->id)
                ->where('reason', 'p2p_sent')
                ->where('source_type', $to->getMorphClass())
                ->where('source_id', $to->getKey())
                ->whereDate('created_at', now()->toDateString())
                ->sum('amount'));
            if ($sentToSameToday + $amount > $dailyPerUser) {
                throw new \RuntimeException("Одному студенту — не более {$dailyPerUser} праны в день.");
            }

            // Отправитель: balance−, lifetime не трогаем.
            PranaTransaction::create([
                'user_id' => $from->id, 'amount' => -$amount, 'reason' => 'p2p_sent',
                'source_type' => $to->getMorphClass(), 'source_id' => $to->getKey(),
                'meta' => ['to_user_id' => $to->id],
            ]);
            DB::table('users')->where('id', $from->id)->decrement('prana_balance', $amount);

            // Получатель: balance+, lifetime НЕ растёт (это не его заработок).
            PranaTransaction::create([
                'user_id' => $to->id, 'amount' => $amount, 'reason' => 'p2p_received',
                'source_type' => $from->getMorphClass(), 'source_id' => $from->getKey(),
                'meta' => ['from_user_id' => $from->id],
            ]);
            DB::table('users')->where('id', $to->id)->increment('prana_balance', $amount);
        });
    }

    /**
     * Включён ли decay. Два независимых ключа (H3297, закрытие @DECIDE §9
     * PLAN_SYSTEMA_SEASON_LIVE_SERVICE_SEPT_2026 — документированный дефолт,
     * вариант B: DB-driven оверрайд, читаемый PranaService):
     *
     *  1. legacy .env-флаг PRANA_DECAY_ENABLED (config prana.decay.enabled) —
     *     ручной операторский выключатель, работает как раньше;
     *  2. seasons.decay_enabled у АКТИВНОГО сезона — ставится season:open,
     *     гасится season:close. Сезон закрыт/флага нет → decay вне сезона
     *     невозможен даже при забытом true в строке неактивного сезона.
     */
    public static function isDecayEnabled(): bool
    {
        if ((bool) config('prana.decay.enabled', false)) {
            return true;
        }

        return Season::where('is_active', true)->where('decay_enabled', true)->exists();
    }

    /**
     * Сгорание праны за бездействие: у студентов, неактивных N+ дней, сжигается
     * процент тратимого баланса. lifetime/ранг не затрагивается. Возвращает число
     * затронутых студентов. Управляется config('prana.decay') + DB-оверрайд
     * активного сезона ({@see isDecayEnabled()}, H3297).
     */
    public function decayInactive(): int
    {
        if (! self::isDecayEnabled()) {
            return 0;
        }

        $cfg = config('prana.decay', []);
        $cutoff = now()->subDays((int) ($cfg['inactive_days'] ?? 30));
        $percent = max(1, min(100, (int) ($cfg['percent'] ?? 10)));

        $users = User::query()
            ->where('prana_balance', '>', 0)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $cutoff);
            })
            ->whereNotNull('last_login_at')
            ->get(['id', 'prana_balance', 'lifetime_prana']);

        $affected = 0;
        foreach ($users as $u) {
            $burn = (int) floor((int) $u->prana_balance * $percent / 100);
            if ($burn <= 0) {
                continue;
            }

            // Decay floor: 50% от стоимости следующего ранга (§5.2)
            $floor = $this->calculateDecayFloor($u->lifetime_prana, $cfg);
            if ($u->prana_balance - $burn < $floor) {
                $burn = max(0, $u->prana_balance - $floor);
                if ($burn <= 0) {
                    continue;
                }
            }

            DB::transaction(function () use ($u, $burn) {
                PranaTransaction::create([
                    'user_id' => $u->id, 'amount' => -$burn, 'reason' => 'decay',
                    'source_type' => null, 'source_id' => null, 'meta' => null,
                ]);
                DB::table('users')->where('id', $u->id)->decrement('prana_balance', $burn);
            });
            $affected++;
        }

        return $affected;
    }

    /**
     * Вычисляет floor для decay по рангу пользователя.
     * Возвращает 50% от порога следующего ранга (§5.2 PLAN_SYSTEMA_SEASON).
     */
    private function calculateDecayFloor(int $lifetimePrana, array $cfg): int
    {
        $mode = $cfg['floor_mode'] ?? 'rank_based';
        if ($mode === 'fixed') {
            return $cfg['floor_fixed'] ?? 0;
        }

        // rank_based: 50% от следующего ранга
        $ranks = config('prana.ranks', []);
        $nextThreshold = null;
        foreach ($ranks as $rank) {
            if ($lifetimePrana < $rank['min']) {
                $nextThreshold = $rank['min'];
                break;
            }
        }

        // Для топ-ранга (Paṇḍita) — floor = 0
        if ($nextThreshold === null) {
            return 0;
        }

        return (int) floor($nextThreshold * 0.5);
    }

    /**
     * Ручное начисление/списание праны администратором.
     * В отличие от award()/spend() — не зависит от PranaSettings::isActive(),
     * не использует source-идемпотентность (каждый клик админа — отдельная запись)
     * и принимает отрицательную сумму как списание.
     *
     * Возвращает свежий баланс после операции.
     * Бросает RuntimeException, если списание уводит баланс ниже нуля.
     */
    public function adminAdjust(
        User $user,
        int $delta,
        User $admin,
        ?string $comment = null,
    ): int {
        if ($delta === 0) {
            return $this->balance($user);
        }

        $reason = $delta > 0 ? 'admin_grant' : 'admin_deduct';

        $meta = array_filter([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'comment' => $comment ? trim($comment) : null,
        ], fn ($v) => $v !== null && $v !== '');

        return DB::transaction(function () use ($user, $delta, $reason, $meta) {
            $current = (int) DB::table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->value('prana_balance');

            $next = $current + $delta;
            if ($next < 0) {
                throw new \RuntimeException(
                    "Списание {$delta} увело бы баланс в минус (текущий: {$current})."
                );
            }

            PranaTransaction::create([
                'user_id' => $user->id,
                'amount' => $delta,
                'reason' => $reason,
                'source_type' => null,
                'source_id' => null,
                'meta' => $meta ?: null,
            ]);

            DB::table('users')->where('id', $user->id)->update(['prana_balance' => $next]);

            // Начисление админом тоже «заработано» → растит lifetime. Списание
            // (delta<0) ранг не понижает — lifetime не трогаем.
            if ($delta > 0) {
                DB::table('users')->where('id', $user->id)->increment('lifetime_prana', $delta);
            }

            return $next;
        });
    }

    /**
     * Сколько праны можно списать на оплату при данной итоговой цене.
     * Учитывает: 30%-кап от цены, текущий баланс, курс конвертации,
     * и оставляет минимум 1 ₽ к оплате (нельзя обнулить заказ праной).
     */
    public function maxSpendableForPrice(User $user, float $finalPrice): int
    {
        if (! PranaSettings::isActive()) {
            return 0;
        }

        $rate = PranaSettings::rate();
        $share = PranaSettings::maxShare();

        if ($finalPrice <= 0) {
            return 0;
        }

        // Можно покрыть не больше доли (в рублях). Округляем вниз.
        $maxRubles = (int) floor($finalPrice * $share);

        // И при этом не сводим заказ к нулю — оставляем минимум 1 ₽.
        $maxRubles = (int) min($maxRubles, max(0, (int) floor($finalPrice) - 1));

        $maxByRules = $maxRubles * $rate;

        // Балансы вроде 5 при rate=10 (после daily_login бонуса) дали бы
        // дробную скидку и копейки в payments.amount. Снэпим до кратного rate.
        $balance = $rate > 0
            ? intdiv($this->balance($user), $rate) * $rate
            : $this->balance($user);

        return max(0, min($maxByRules, $balance));
    }

    /**
     * Перевести количество праны в скидку в рублях.
     */
    public function pranaToRubles(int $prana): float
    {
        return $prana / PranaSettings::rate();
    }

    /**
     * Дневной бонус: начисляется один раз в сутки. Идемпотентность
     * через source = (User, source_id=YYYYMMDD).
     */
    public function awardDailyLogin(User $user): bool
    {
        if (! PranaSettings::isActive()) {
            return false;
        }

        $amount = PranaSettings::reward('daily_login');
        if ($amount <= 0) {
            return false;
        }

        $sourceId = (int) now()->format('Ymd');

        try {
            return DB::transaction(function () use ($user, $amount, $sourceId) {
                PranaTransaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'reason' => 'daily_login',
                    'source_type' => $user->getMorphClass(),
                    'source_id' => $sourceId,
                    'meta' => null,
                ]);

                DB::table('users')->where('id', $user->id)->increment('prana_balance', $amount);

                return true;
            });
        } catch (UniqueConstraintViolationException $e) {
            return false;
        } catch (\Throwable $e) {
            if ($e instanceof QueryException && $e->getCode() === '23000') {
                return false;
            }
            Log::warning('PranaService::awardDailyLogin failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
