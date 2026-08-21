<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ImpersonationAuditResource\Pages;
use App\Models\ImpersonationAudit;
use App\Support\Impersonation;
use App\Support\RoleGate;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Журнал режима просмотра за пользователя (H1947) — кто и за кого входил.
 * Полностью read-only: строки пишет только {@see Impersonation}.
 *
 * Виден ТОЛЬКО супер-админу, и только ему: это единственная роль, которая может
 * режим начать, а сам журнал показывает, кто ходил по чужим кабинетам. Под
 * режимом куратора страница исчезает сама — прав супер-админа в сессии в этот
 * момент нет.
 */
class ImpersonationAuditResource extends Resource
{
    protected static ?string $model = ImpersonationAudit::class;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 95;

    protected static ?string $navigationLabel = 'Входы за пользователя';

    protected static ?string $pluralModelLabel = 'Входы за пользователя';

    protected static ?string $modelLabel = 'вход за пользователя';

    public static function canViewAny(): bool
    {
        return Impersonation::enabled() && RoleGate::isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Вход')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('stopped_at')
                    ->label('Выход')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('— не закрыт —')
                    ->sortable(),

                Tables\Columns\TextColumn::make('impersonator_name')
                    ->label('Кто вошёл')
                    ->searchable(),

                Tables\Columns\TextColumn::make('target_name')
                    ->label('За кого')
                    ->searchable(),

                Tables\Columns\TextColumn::make('mode')
                    ->label('Режим')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Impersonation::MODE_MANAGER => 'Куратор',
                        Impersonation::MODE_TEACHER => 'Преподаватель',
                        default => 'Студент',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Impersonation::MODE_MANAGER => 'warning',
                        Impersonation::MODE_TEACHER => 'success',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('user_agent')
                    ->label('Браузер')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('mode')
                    ->label('Режим')
                    ->options([
                        Impersonation::MODE_STUDENT => 'Студент',
                        Impersonation::MODE_MANAGER => 'Куратор',
                        Impersonation::MODE_TEACHER => 'Преподаватель',
                    ]),

                Tables\Filters\Filter::make('open')
                    ->label('Только незакрытые')
                    ->query(fn ($query) => $query->whereNull('stopped_at')),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImpersonationAudits::route('/'),
        ];
    }
}
