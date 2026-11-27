<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipTier;
use App\Models\Tariff;
use App\Services\Membership\LadderV2;
use Illuminate\Console\Command;

/**
 * Идемпотентное включение тарифов «Оптимальный» (basic, 1000 ₽) с даты лестницы.
 * Без --apply только печатает, что будет сделано.
 */
class ActivateLadderV2Tariffs extends Command
{
    protected $signature = 'membership:activate-ladder-v2 {--apply}';

    protected $description = 'Включить тарифы basic (1000 ₽/мес) после 01-12-2026';

    public function handle(LadderV2 $ladder): int
    {
        if (! $ladder->active()) {
            $this->info('Лестница v2 ещё не активна — ничего не меняем.');

            return self::SUCCESS;
        }

        $tariffs = Tariff::query()
            ->where('membership_tier', MembershipTier::Basic->value)
            ->where('is_active', false)
            ->orderBy('id')->get();

        if ($tariffs->isEmpty()) {
            $this->info('Неактивных тарифов basic нет — no-op.');

            return self::SUCCESS;
        }

        foreach ($tariffs as $tariff) {
            $this->line("Включаем #{$tariff->id} «{$tariff->title}»");
            if ($this->option('apply')) {
                $tariff->forceFill(['is_active' => true])->save();
            }
        }

        $this->info($this->option('apply') ? 'Готово.' : 'Сухой прогон — повторите с --apply.');

        return self::SUCCESS;
    }
}
