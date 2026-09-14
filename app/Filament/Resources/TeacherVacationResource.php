<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeacherVacationResource\Pages\ListTeacherVacations;
use App\Models\Teacher;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * H4253: лёгкая админка каникул преподавателей.
 *
 * Ruling MG 06-09 («what teacher can do, managers and admin can do as well»):
 * TeacherResource остаётся AdminOnly (там зарплатный контур), а отпускные окна
 * правят admin И manager через этот урезанный ресурс — без финансов.
 * Полные права учитываются тем, что окно преподавателя также ставится им самим
 * командой в Telegram-чате группы.
 */
class TeacherVacationResource extends Resource
{
    protected static ?string $model = Teacher::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'Каникулы преподавателей';

    protected static ?string $slug = 'teacher-vacations';

    protected static ?string $navigationGroup = 'Пользователи';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Преподаватель')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('on_vacation_from')
                    ->label('С')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('on_vacation_until')
                    ->label('По')
                    ->date('d.m.Y')
                    ->placeholder('уточняется'),
                TextColumn::make('vacation_state')
                    ->label('Статус')
                    ->badge()
                    ->state(fn (Teacher $record): string => $record->isOnVacationOn(now())
                        ? 'На каникулах'
                        : 'Занятия идут')
                    ->color(fn (string $state): string => $state === 'На каникулах' ? 'warning' : 'success'),
            ])
            ->actions([
                EditAction::make()
                    ->label('Окно каникул')
                    ->icon('heroicon-o-pencil-square')
                    ->form([
                        DatePicker::make('on_vacation_from')
                            ->label('Начало каникул'),
                        DatePicker::make('on_vacation_until')
                            ->label('Конец каникул')
                            ->helperText('Пусто = дата выхода неизвестна («уточняется»).'),
                    ]),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeacherVacations::route('/'),
        ];
    }
}
