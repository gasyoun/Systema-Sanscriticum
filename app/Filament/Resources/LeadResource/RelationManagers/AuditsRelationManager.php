<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadResource\RelationManagers;

use App\Models\LeadAudit;
use App\Support\RoleGate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only таймлайн аудита заявки (кто/что/когда правил) прямо на странице
 * редактирования. Данные пишет LeadAuditObserver — здесь только показ.
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
                    ->description(fn (LeadAudit $r): ?string => $r->admin?->email),

                Tables\Columns\TextColumn::make('action')
                    ->label('Действие')
                    ->badge()
                    ->formatStateUsing(fn (LeadAudit $r): string => $r->actionLabel())
                    ->color(fn (string $state): string => match ($state) {
                        LeadAudit::ACTION_CREATED => 'success',
                        LeadAudit::ACTION_UPDATED => 'warning',
                        LeadAudit::ACTION_DELETED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('changes')
                    ->label('Изменения')
                    ->formatStateUsing(fn (LeadAudit $r): string => $r->summary())
                    ->wrap()
                    ->size('sm')
                    ->color('gray'),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Пока нет записей')
            ->emptyStateDescription('Здесь появятся все изменения этой заявки.');
    }

    protected function canCreate(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // Таймлайн — инструмент контроля владельца; менеджеру не нужен.
        return RoleGate::adminOnly();
    }
}
