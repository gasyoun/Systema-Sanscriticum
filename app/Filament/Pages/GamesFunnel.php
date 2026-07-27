<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\GameEvent;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * Воронка бесплатных тренажёров /lila (H1360): показы -> решения -> стена -> CTA.
 *
 * Верхний вход Tier-0 воронки, поэтому доступ — как у SalesFunnelChart:
 * менеджер и админ (супер-админ всегда), преподаватель/бухгалтер — нет.
 */
class GamesFunnel extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationLabel = 'Воронка тренажёров';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $title = 'Воронка бесплатных тренажёров';

    protected static ?string $slug = 'games-funnel';

    protected static string $view = 'filament.pages.games-funnel';

    /** Окно отчёта в днях (совпадает с games:funnel --days). */
    public int $days = 14;

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    /**
     * @return array<int, array{drill:string, band:?string, plays:int, completes:int, walls:int, cta:int}>
     */
    public function getRowsProperty(): array
    {
        return GameEvent::funnel(now()->subDays(max(1, $this->days)));
    }

    /**
     * Итоги по всем строкам — шапка воронки.
     *
     * @return array{plays:int, completes:int, walls:int, cta:int}
     */
    public function getTotalsProperty(): array
    {
        $rows = $this->rows;

        return [
            'plays' => array_sum(array_column($rows, 'plays')),
            'completes' => array_sum(array_column($rows, 'completes')),
            'walls' => array_sum(array_column($rows, 'walls')),
            'cta' => array_sum(array_column($rows, 'cta')),
        ];
    }
}
