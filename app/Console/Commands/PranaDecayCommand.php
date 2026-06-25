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
        if (! config('prana.decay.enabled')) {
            $this->info('Decay выключен (config prana.decay.enabled=false). Пропуск.');

            return self::SUCCESS;
        }

        $affected = $prana->decayInactive();
        $this->info("Decay применён к {$affected} студент(ам).");

        return self::SUCCESS;
    }
}
