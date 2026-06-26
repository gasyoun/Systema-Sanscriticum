<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PranaPerk;
use App\Models\PranaRedemption;
use App\Models\User;
use App\Services\Prana\PranaService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Магазин праны (spend-sink): студент тратит прану на перк. Прана списывается
 * (PranaService::spend), уменьшается остаток, создаётся заявка PranaRedemption
 * (status pending) — админ исполняет её вручную в Filament.
 */
class PranaShopService
{
    public function __construct(private PranaService $prana) {}

    /**
     * Купить перк за прану. Бросает RuntimeException при недоступности/нехватке праны.
     */
    public function redeem(User $user, PranaPerk $perk): PranaRedemption
    {
        return DB::transaction(function () use ($user, $perk) {
            // Блокируем перк на чтении — race по остатку.
            $locked = PranaPerk::lockForUpdate()->find($perk->id);

            if (! $locked || ! $locked->is_active) {
                throw new RuntimeException('Этот перк сейчас недоступен.');
            }
            if ($locked->stock !== null && $locked->stock <= 0) {
                throw new RuntimeException('Этот перк закончился.');
            }

            // Списываем прану (бросит RuntimeException при нехватке — откатит транзакцию).
            $this->prana->spend($user, (int) $locked->cost, 'spent_on_perk', $locked, [
                'perk_name' => $locked->name,
            ]);

            if ($locked->stock !== null) {
                $locked->decrement('stock');
            }

            return PranaRedemption::create([
                'user_id' => $user->id,
                'prana_perk_id' => $locked->id,
                'perk_name' => $locked->name,
                'cost' => (int) $locked->cost,
                'status' => PranaRedemption::STATUS_PENDING,
            ]);
        });
    }
}
