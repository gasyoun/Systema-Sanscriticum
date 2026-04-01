<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeacherResource\Pages;
use App\Filament\Resources\TeacherResource\RelationManagers;
use App\Models\Teacher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TeacherResource extends Resource
{
    protected static ?string $model = Teacher::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Section::make('Личные данные')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('name')
                            ->label('ФИО Преподавателя')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('phone')
                            ->label('Телефон')
                            ->tel(),
                    ])->columns(3),

                \Filament\Forms\Components\Section::make('Соцсети и Реквизиты')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('telegram')
                            ->label('Telegram (Ник)'),
                        \Filament\Forms\Components\TextInput::make('vk')
                            ->label('ВКонтакте (Ссылка)'),
                        \Filament\Forms\Components\Textarea::make('requisites')
                            ->label('Реквизиты для выплаты ЗП')
                            ->columnSpanFull(),
                        \Filament\Forms\Components\RichEditor::make('bio')
                            ->label('Биография / Регалии')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                
                \Filament\Tables\Columns\TextColumn::make('telegram')
                    ->label('Telegram')
                    ->icon('heroicon-m-paper-airplane'),

                \Filament\Tables\Columns\TextColumn::make('courses_count')
                    ->counts('courses') // Встроенная фишка Filament для подсчета связей
                    ->label('Курсов')
                    ->badge()
                    ->color('info'),

                // А ВОТ И НАША МАГИЯ ЗАРПЛАТЫ!
                // КОЛОНКА "БАЛАНС" С ВЫПАДАЮЩИМ ОКНОМ
                \Filament\Tables\Columns\TextColumn::make('balance')
                    ->label('К выплате (Баланс)')
                    ->state(function (\App\Models\Teacher $record) {
                        $earned = $record->calculateEarnings(); // Заработал всего
                        $paid = $record->payouts()->sum('amount'); // Уже выплачено
                        return number_format($earned - $paid, 0, '.', ' ') . ' ₽';
                    })
                    ->badge()
                    // Если мы должны деньги - желтый, если в расчете - зеленый
                    ->color(fn (string $state) => str_contains($state, '-') || $state === '0 ₽' ? 'success' : 'warning')
                    ->icon('heroicon-m-wallet')
                    ->action(
                        // ДЕЙСТВИЕ ПРИ КЛИКЕ: Открываем окно статистики и выплат
                        \Filament\Tables\Actions\Action::make('manage_finances')
                            ->modalHeading(fn (\App\Models\Teacher $record) => 'Финансы: ' . $record->name)
                            ->modalWidth('md')
                            ->form([
                                // Блок 1: Статистика (только для чтения)
                                \Filament\Forms\Components\Placeholder::make('month_stats')
                                    ->label('📈 Заработано за текущий месяц:')
                                    ->content(fn (\App\Models\Teacher $record) => 
                                        number_format($record->calculateEarnings(now()->startOfMonth(), now()->endOfMonth()), 0, '.', ' ') . ' ₽'
                                    ),
                                
                                \Filament\Forms\Components\Placeholder::make('total_stats')
                                    ->label('💰 Заработано за всё время:')
                                    ->content(fn (\App\Models\Teacher $record) => 
                                        number_format($record->calculateEarnings(), 0, '.', ' ') . ' ₽'
                                    ),

                                \Filament\Forms\Components\Placeholder::make('paid_stats')
                                    ->label('✅ Уже выплачено вами:')
                                    ->content(fn (\App\Models\Teacher $record) => 
                                        number_format($record->payouts()->sum('amount'), 0, '.', ' ') . ' ₽'
                                    ),

                                // Блок 2: Форма для новой выплаты
                                \Filament\Forms\Components\Section::make('Зафиксировать новую выплату')
                                    ->description('Внесите сюда сумму, которую вы перевели преподавателю.')
                                    ->schema([
                                        \Filament\Forms\Components\TextInput::make('payout_amount')
                                            ->label('Сумма выплаты (₽)')
                                            ->numeric()
                                            ->required(),
                                        \Filament\Forms\Components\TextInput::make('comment')
                                            ->label('Комментарий (необязательно)')
                                            ->placeholder('Например: ЗП за март'),
                                    ])
                            ])
                            // Что делаем, когда админ нажал "Сохранить"
                            ->action(function (\App\Models\Teacher $record, array $data) {
                                $record->payouts()->create([
                                    'amount' => $data['payout_amount'],
                                    'comment' => $data['comment'],
                                ]);
                                \Filament\Notifications\Notification::make()
                                    ->title('Выплата успешно записана!')
                                    ->success()
                                    ->send();
                            })
                            ->modalSubmitActionLabel('Записать выплату')
                    ),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\BulkActionGroup::make([
                    \Filament\Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeachers::route('/'),
            'create' => Pages\CreateTeacher::route('/create'),
            'edit' => Pages\EditTeacher::route('/{record}/edit'),
        ];
    }
}
