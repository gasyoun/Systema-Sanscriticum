<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Prana\PranaService;
use Illuminate\Console\Command;

/**
 * Сгорание праны за бездействие. Запускать по расписанию (напр. еженедельно).
 * Безопасно: если config('prana.decay.enabled') = false, ничего не делает.
 */
class PranaDecayCommand extends Command
{
    protected $signature = 'prana:decay';

    protected $description = 'Сжечь часть тратимой праны у давно неактивных студентов (decay)';

    public function handle(PranaService $prana): int
    {
        // H3297: единый гейт с decayInactive() — env-флаг ИЛИ активный сезон
        // со seasons.decay_enabled=true.
        if (! PranaService::isDecayEnabled()) {
            $this->info('Decay выключен (нет ни env-флага PRANA_DECAY_ENABLED, ни активного сезона с decay_enabled). Пропуск.');

            return self::SUCCESS;
        }

        $affected = $prana->decayInactive();
        $this->info("Decay применён к {$affected} студент(ам).");

        return self::SUCCESS;
    }
}
