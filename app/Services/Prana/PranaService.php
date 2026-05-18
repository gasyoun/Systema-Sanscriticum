<?php

declare(strict_types=1);

namespace App\Services\Prana;

use App\Models\PranaTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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

                DB::table('users')->where('id', $user->id)->increment('prana_balance', $amount);

                return true;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Уже начисляли — это не ошибка.
            return false;
        } catch (\Throwable $e) {
            // Некоторые драйверы кидают QueryException с кодом 23000 вместо UniqueConstraintViolationException
            if ($e instanceof \Illuminate\Database\QueryException && $e->getCode() === '23000') {
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return false;
        } catch (\Throwable $e) {
            if ($e instanceof \Illuminate\Database\QueryException && $e->getCode() === '23000') {
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return false;
        } catch (\Throwable $e) {
            if ($e instanceof \Illuminate\Database\QueryException && $e->getCode() === '23000') {
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
