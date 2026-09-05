<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\IpExpenseCategory;
use App\Filament\Concerns\FinanceAccess;
use App\Filament\Resources\IpExpenseResource\Pages;
use App\Models\IpExpense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Контур «Расходы ИП» (H4188): книга «Расходы по ИП» переехала из Google
 * Sheets сюда. MVP-ввод с телефона: создать расход в тот же день — дата по
 * умолчанию сегодня, минимум обязательных полей. История книги долита
 * ip-expenses:import-book.
 *
 * Append-only (конвенции payment_audits): удаление запрещено (модель бросает,
 * ресурс не даёт Delete), правки пишутся в ip_expense_audits. Вкладки книги
 * месяца = фильтр по месяцу даты траты.
 */
class IpExpenseResource extends Resource
{
    use FinanceAccess;

    protected static ?string $model = IpExpense::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 83;

    protected static ?string $navigationLabel = 'Расходы ИП';

    protected static ?string $modelLabel = 'Расход ИП';

    protected static ?string $pluralModelLabel = 'Расходы ИП';

    public static function canDelete($record): bool
    {
        // Append-only: строки контура не удаляются (правьте поля, аудит спасёт diff).
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Расход ИП')
                ->description('Строка контура книги «Расходы по ИП». Удаление запрещено — правки аудируются.')
                ->schema([
                    Forms\Components\DatePicker::make('spent_at')
                        ->label('Дата траты')
                        ->default(now())
                        ->maxDate(now()->endOfDay())
                        ->validationMessages(['before_or_equal' => 'Дата траты не может быть в будущем — проверьте день и месяц.'])
                        ->native(false),

                    Forms\Components\TextInput::make('payee')
                        ->label('Получатель')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('amount')
                        ->label('Сумма, ₽')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('₽'),

                    Forms\Components\Select::make('category')
                        ->label('Статья')
                        ->options(IpExpenseCategory::options())
                        ->required()
                        ->default(IpExpenseCategory::Other->value)
                        ->native(false),

                    Forms\Components\TextInput::make('account')
                        ->label('Счёт списания')
                        ->placeholder('ИП / Гасунс сбер / PayPal…')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('fx_note')
                        ->label('Валютная деталь')
                        ->placeholder('400 евро PayPal — если платили в валюте')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('note')
                        ->label('Примечание')
                        ->rows(2)
                        ->maxLength(1000),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('spent_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('spent_at')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payee')
                    ->label('Получатель')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('category')
                    ->label('Статья')
                    ->badge()
                    ->formatStateUsing(fn (IpExpenseCategory $state): string => $state->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('RUB')),

                Tables\Columns\TextColumn::make('account')
                    ->label('Счёт')
                    ->toggleable()
                    ->limit(24),

                Tables\Columns\TextColumn::make('fx_note')
                    ->label('Валюта')
                    ->toggleable()
                    ->limit(24)
                    ->default('—'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Примечание')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('source_tab')
                    ->label('Вкладка книги')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Эквивалент месячных вкладок книги: фильтр по месяцу даты траты.
                Tables\Filters\SelectFilter::make('month')
                    ->label('Месяц')
                    ->options(fn (): array => self::monthOptions())
                    ->query(function ($query, array $data) {
                        if (! isset($data['value']) || ! preg_match('/^(\d{4})-(\d{2})$/', (string) $data['value'], $m)) {
                            return $query;
                        }

                        return $query->whereYear('spent_at', (int) $m[1])->whereMonth('spent_at', (int) $m[2]);
                    }),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Статья')
                    ->options(IpExpenseCategory::options()),

                Tables\Filters\Filter::make('undated')
                    ->label('Без даты')
                    ->query(fn ($query) => $query->whereNull('spent_at')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // Пусто: bulk-delete в append-only контуре отсутствует.
            ]);
    }

    /**
     * Месяцы, реально присутствующие в данных (вкладки книги), новые сверху.
     *
     * @return array<string,string>
     */
    public static function monthOptions(): array
    {
        $months = IpExpense::query()
            ->whereNotNull('spent_at')
            ->selectRaw('distinct substr(spent_at, 1, 7) as m')
            ->pluck('m')
            ->mapWithKeys(fn (string $m): array => [$m => Carbon::parse($m.'-01')->translatedFormat('F Y')]);

        return $months->sortKeysDesc()->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpExpenses::route('/'),
            'create' => Pages\CreateIpExpense::route('/create'),
            'edit' => Pages\EditIpExpense::route('/{record}/edit'),
        ];
    }
}
