<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\CabinetProbeRun;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;

/**
 * История cabinet:probe (H1794) — read-only.
 */
class CabinetProbeHealth extends Page implements HasTable
{
    use AdminOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Здоровье кабинета';

    protected static ?string $title = 'Здоровье кабинета (cabinet:probe)';

    protected static ?string $navigationGroup = 'Система';

    protected static ?int $navigationSort = 95;

    protected static string $view = 'filament.pages.cabinet-probe-health';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public function table(Table $table): Table
    {
        if (! Schema::hasTable('cabinet_probe_runs')) {
            return $table->query(CabinetProbeRun::query()->whereRaw('0=1'))
                ->columns([])
                ->emptyStateHeading('Таблица cabinet_probe_runs ещё не создана')
                ->emptyStateDescription('php artisan migrate — миграция 2026_07_28_140000_create_cabinet_probe_runs_table');
        }

        return $table
            ->query(CabinetProbeRun::query()->orderByDesc('ran_at'))
            ->columns([
                Tables\Columns\TextColumn::make('ran_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\IconColumn::make('healthy')
                    ->label('OK')
                    ->boolean(),
                Tables\Columns\IconColumn::make('critical')
                    ->label('Critical fail')
                    ->boolean(),
                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('ms')
                    ->numeric(),
                Tables\Columns\TextColumn::make('failure_count')
                    ->label('Fails'),
                Tables\Columns\TextColumn::make('summary')
                    ->label('Сводка')
                    ->wrap()
                    ->limit(120),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
