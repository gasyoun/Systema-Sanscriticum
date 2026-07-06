<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ExpenseCategory;
use App\Filament\Concerns\AccountingOnly;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Ручной ввод операционных расходов (opex): эквайринг, хостинг, подрядчики,
 * налоги. Расходная сторона ОПиУ, которой у LMS не было. Доступ — бухгалтер и
 * супер-админ (AccountingOnly), как у выплат преподавателям.
 */
class ExpenseResource extends Resource
{
    use AccountingOnly;

    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 82;

    protected static ?string $navigationLabel = 'Расходы (opex)';

    protected static ?string $modelLabel = 'Расход';

    protected static ?string $pluralModelLabel = 'Расходы';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Операционный расход')
                ->description('Прочие расходы бизнеса, кроме маркетинга и зарплат — они учитываются отдельно.')
                ->schema([
                    Forms\Components\DatePicker::make('spent_at')
                        ->label('Дата траты')
                        ->required()
                        ->default(now())
                        ->native(false),

                    Forms\Components\Select::make('category')
                        ->label('Статья расхода')
                        ->options(ExpenseCategory::options())
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('amount')
                        ->label('Сумма, ₽')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('₽'),

                    Forms\Components\TextInput::make('counterparty')
                        ->label('Контрагент')
                        ->maxLength(255)
                        ->placeholder('Банк, хостер, подрядчик — необязательно'),

                    Forms\Components\Textarea::make('note')
                        ->label('Комментарий')
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

                Tables\Columns\TextColumn::make('category')
                    ->label('Статья')
                    ->formatStateUsing(fn (ExpenseCategory $state): string => $state->label())
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('RUB')),

                Tables\Columns\TextColumn::make('counterparty')
                    ->label('Контрагент')
                    ->toggleable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Комментарий')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Статья')
                    ->options(ExpenseCategory::options()),
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
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
