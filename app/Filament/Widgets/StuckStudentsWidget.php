<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\StuckStudentsReport;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Дашборд-виджет «Застрявшие студенты»: кому нужна помощь куратора — давно
 * неактивные и/или зависшие на доработке ДЗ. Логика — в StuckStudentsReport.
 */
class StuckStudentsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Застрявшие студенты';

    public static function canView(): bool
    {
        // Куратор = админ-подобные роли (тот же гейт, что у студентов в UserResource).
        return RoleGate::any(Roles::ADMIN, Roles::ACCOUNTANT);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(StuckStudentsReport::query()->orderBy('last_activity_at'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Студент')
                    ->weight('bold')
                    ->description(fn (User $r): string => $r->email.' · ID: '.$r->id)
                    ->url(fn (User $r): string => UserResource::getUrl('view', ['record' => $r]))
                    ->wrap(),

                Tables\Columns\TextColumn::make('reasons')
                    ->label('Почему застрял')
                    ->badge()
                    ->color('warning')
                    ->state(fn (User $r): array => StuckStudentsReport::reasonsFor($r)),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Последний визит')
                    ->placeholder('Никогда')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('open_card')
                    ->label('Карточка')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (User $r): string => UserResource::getUrl('view', ['record' => $r])),
            ])
            ->emptyStateHeading('Застрявших студентов нет')
            ->emptyStateDescription('Никто не выпал из активности и не завис на доработке — отлично.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([10, 25, 50]);
    }
}
