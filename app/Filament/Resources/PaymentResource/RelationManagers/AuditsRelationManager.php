<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentResource\RelationManagers;

use App\Models\PaymentAudit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only таймлайн аудита платежа (кто/что/когда правил) прямо на странице
 * редактирования. Данные пишет PaymentAuditObserver — здесь только показ.
 */
class AuditsRelationManager extends RelationManager
{
    protected static string $relationship = 'audits';

    protected static ?string $title = 'История изменений';

    protected static ?string $icon = 'heroicon-o-clock';

    /** Лог неизменяем: ни создавать, ни править, ни удалять вручную нельзя. */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->size('sm')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('admin_name')
                    ->label('Кто')
                    ->weight(FontWeight::Bold)
                    ->description(fn (PaymentAudit $r): ?string => $r->admin?->email),

                Tables\Columns\TextColumn::make('action')
                    ->label('Действие')
                    ->badge()
                    ->formatStateUsing(fn (PaymentAudit $r): string => $r->actionLabel())
                    ->color(fn (string $state): string => match ($state) {
                        PaymentAudit::ACTION_CREATED => 'success',
                        PaymentAudit::ACTION_UPDATED => 'warning',
                        PaymentAudit::ACTION_DELETED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('changes')
                    ->label('Изменения')
                    ->formatStateUsing(fn (PaymentAudit $r): string => $r->summary())
                    ->wrap()
                    ->size('sm')
                    ->color('gray'),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Пока нет записей')
            ->emptyStateDescription('Здесь появятся все изменения этого платежа.');
    }

    /** Таблицу не оборачиваем в форму: создание/правка запрещены. */
    protected function canCreate(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // Таймлайн — инструмент контроля владельца; менеджеру/бухгалтеру не нужен.
        return \App\Support\RoleGate::adminOnly();
    }
}
