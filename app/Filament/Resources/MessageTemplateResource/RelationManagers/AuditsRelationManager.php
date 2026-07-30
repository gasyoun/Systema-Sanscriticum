<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageTemplateResource\RelationManagers;

use App\Models\MessageTemplateAudit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only таймлайн правок шаблона (кто/что/когда менял) прямо на странице
 * редактирования (H1932). Данные пишет MessageTemplateAuditObserver — здесь
 * только показ. Зеркало LeadResource\AuditsRelationManager, но видим и
 * куратору: раз куратор правит тексты, он должен видеть и их историю.
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
                    ->description(fn (MessageTemplateAudit $r): ?string => $r->admin?->email),

                Tables\Columns\TextColumn::make('action')
                    ->label('Действие')
                    ->badge()
                    ->formatStateUsing(fn (MessageTemplateAudit $r): string => $r->actionLabel())
                    ->color(fn (string $state): string => match ($state) {
                        MessageTemplateAudit::ACTION_CREATED => 'success',
                        MessageTemplateAudit::ACTION_UPDATED => 'warning',
                        MessageTemplateAudit::ACTION_DELETED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('changes')
                    ->label('Изменения')
                    ->formatStateUsing(fn (MessageTemplateAudit $r): string => $r->summary())
                    ->wrap()
                    ->size('sm')
                    ->color('gray'),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Пока нет записей')
            ->emptyStateDescription('Здесь появятся все изменения этого шаблона.');
    }

    protected function canCreate(): bool
    {
        return false;
    }
}
