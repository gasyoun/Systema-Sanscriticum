<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\StudentAvailabilityWindowResource\Pages;
use App\Models\StudentAvailabilityWindow;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Окна доступности (26-11-2026): куратор видит и добавляет приоритетные строки.
 * Студенческие строки здесь только читаются — редактирует их студент сам.
 */
class StudentAvailabilityWindowResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = StudentAvailabilityWindow::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Студенты';

    protected static ?string $navigationLabel = 'Окна доступности';

    protected static ?string $pluralModelLabel = 'Окна доступности';

    protected static ?string $modelLabel = 'Окно';

    protected static ?int $navigationSort = 137;

    public static function canViewAny(): bool
    {
        return config('features.availability_windows') && RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')
                ->label('Студент')->relationship('user', 'name')
                ->searchable()->preload()->required(),
            Forms\Components\Select::make('source')
                ->label('Источник')
                ->options([
                    StudentAvailabilityWindow::SOURCE_CURATOR => 'Куратор (приоритет)',
                    StudentAvailabilityWindow::SOURCE_STUDENT => 'Студент',
                ])
                ->default(StudentAvailabilityWindow::SOURCE_CURATOR)->required(),
            Forms\Components\Select::make('kind')
                ->label('Действие')
                ->options([
                    StudentAvailabilityWindow::KIND_ALLOW => 'Разрешить (окно)',
                    StudentAvailabilityWindow::KIND_DENY => 'Запретить (blackout)',
                ])
                ->default(StudentAvailabilityWindow::KIND_ALLOW)->required(),
            Forms\Components\TextInput::make('rrule')
                ->label('RRULE')->default('FREQ=WEEKLY;BYDAY=SA')->required()
                ->helperText('Поддерживается FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU'),
            Forms\Components\Select::make('repeat_mode')
                ->label('Повтор')
                ->options([
                    StudentAvailabilityWindow::REPEAT_WEEKLY => 'Каждую неделю',
                    StudentAvailabilityWindow::REPEAT_CYCLE => 'Цикл: N можно / M нельзя',
                ])
                ->default(StudentAvailabilityWindow::REPEAT_WEEKLY)->required(),
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('cycle_on_days')->label('Можно дней')->numeric()->minValue(1)->maxValue(14)->default(2),
                Forms\Components\TextInput::make('cycle_off_days')->label('Нельзя дней')->numeric()->minValue(1)->maxValue(14)->default(2),
                Forms\Components\DatePicker::make('anchor_date')->label('Дата старта цикла')
                    ->helperText('Отсчёт цикла идёт от этой даты; для еженедельного повтора не заполняется.'),
            ]),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TimePicker::make('start_time')->label('Начало')->seconds(false)->required(),
                Forms\Components\TimePicker::make('end_time')->label('Конец (23:59 = до конца дня)')->seconds(false)->required(),
                Forms\Components\TextInput::make('tzid')->label('TZID')->default('Europe/Moscow')->required(),
                Forms\Components\DatePicker::make('valid_from')->label('Действует с'),
                Forms\Components\DatePicker::make('valid_until')->label('Действует до'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Студент')->searchable(),
                Tables\Columns\TextColumn::make('source')->label('Источник')->badge(),
                Tables\Columns\TextColumn::make('kind')->label('Действие')->badge()
                    ->color(fn (string $state) => $state === StudentAvailabilityWindow::KIND_DENY ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('rrule')->label('RRULE'),
                Tables\Columns\TextColumn::make('interval')->label('Окно')
                    ->getStateUsing(fn (StudentAvailabilityWindow $r) => $r->start_time.'–'.$r->end_time),
                Tables\Columns\TextColumn::make('tzid')->label('TZ'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->options([
                    StudentAvailabilityWindow::SOURCE_STUDENT => 'Студент',
                    StudentAvailabilityWindow::SOURCE_CURATOR => 'Куратор',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudentAvailabilityWindows::route('/'),
        ];
    }
}
