<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Финансы';

    protected static ?string $pluralModelLabel = 'Транзакции';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Детали транзакции')->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Студент')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('course_id')
                        ->label('Курс')
                        ->relationship('course', 'title')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('tariff')
                        ->label('Тариф (Доступ)')
                        ->options(function () {
                            $options = [
                                'full' => 'Весь курс целиком',
                                'deposit' => '📌 Бронь курса (предоплата)',
                                'trial' => '🎟 Пробное занятие',
                                'Расход' => '💸 Системный расход / Возврат',
                            ];

                            for ($i = 1; $i <= 100; $i++) {
                                $startLesson = ($i - 1) * 4 + 1;
                                $endLesson = $i * 4;
                                $options["block_{$i}"] = "Блок {$i} (Занятия {$startLesson}-{$endLesson})";
                            }

                            return $options;
                        })
                        ->searchable()
                        ->default('full')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($state === 'full' || $state === 'Расход') {
                                $set('start_block', null);
                                $set('end_block', null);
                            } elseif (str_starts_with($state ?? '', 'block_')) {
                                $blockNum = (int) str_replace('block_', '', $state);
                                $set('start_block', $blockNum);
                                $set('end_block', $blockNum);
                            }
                        })
                        ->columnSpanFull(),
                ])->columns(2),

                Forms\Components\Section::make('Финансы')->schema([
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Forms\Components\TextInput::make('amount')
                                ->label('Сумма (₽)')
                                ->numeric()
                                ->required(),

                            Forms\Components\TextInput::make('start_block')
                                ->label('Оплачен с блока №')
                                ->numeric()
                                ->helperText('Например: 52'),

                            Forms\Components\TextInput::make('end_block')
                                ->label('По блок №')
                                ->numeric()
                                ->helperText('Пусто, если курс куплен целиком'),

                            Forms\Components\TextInput::make('discount_percent')
                                ->label('Скидка, %')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->helperText('Процентная скидка. Заполняется автоматически при покупке.'),

                            Forms\Components\TextInput::make('discount_amount')
                                ->label('Скидка, ₽')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('₽')
                                ->helperText('Сумма скидки (для фиксированной). При проценте — рублёвый эквивалент.'),
                        ]),

                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label('Дата платежа')
                            ->default(now())
                            ->required()
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d.m.Y H:i')
                            ->helperText('По умолчанию — текущий момент'),

                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                'pending' => 'Ожидает оплаты',
                                'paid' => 'Оплачено',
                                'canceled' => 'Отменено / Ошибка',
                            ])
                            ->default('paid')
                            ->required(),

                        Forms\Components\TextInput::make('transaction_id')
                            ->label('ID транзакции (Банк / Расход)')
                            ->maxLength(255),
                    ]),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 1. КОМПАКТНАЯ ДАТА (без времени)
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->date('d.m.Y') // <-- Убрали время, оставили только компактную дату
                    ->sortable()
                    ->color('gray')
                    ->size('sm'),

                // 2. СТУДЕНТ (Добавили wrap, чтобы сузить колонку)
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable()
                    ->wrap() // <-- МАГИЯ ЗДЕСЬ: Длинные ФИО перенесутся на новую строку
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    ->description(fn (Payment $record): string => $record->user->email ?? 'Нет email'),

                // 3. КУРС (Займет всё освободившееся пространство)
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс и Тариф')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (Payment $record) {
                        // Единая пометка операции (Payment::operationLabel).
                        // Для брони дополнительно показываем дату зачёта депозита.
                        $label = $record->operationLabel();

                        if ($record->tariff === 'deposit' && $record->deposit_consumed_at) {
                            $label .= ' · зачтено '.$record->deposit_consumed_at->format('d.m.Y');
                        }

                        return $label;
                    }),

                // 4. СУММА
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB', locale: 'ru')
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::ExtraBold)
                    ->color(fn (Payment $record) => $record->amount < 0 ? 'danger' : ($record->status === 'paid' ? 'success' : 'gray'))
                    ->alignment(\Filament\Support\Enums\Alignment::End),

                // Пометка «по скидке»: бейдж «-10%» / «-1000 ₽», если платёж со скидкой.
                Tables\Columns\TextColumn::make('discount')
                    ->label('Скидка')
                    ->badge()
                    ->color('success')
                    ->getStateUsing(fn (Payment $record): ?string => $record->discountLabel() ?: null)
                    ->alignment(\Filament\Support\Enums\Alignment::Center),

                // 5. СТАТУС
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'paid', 'success' => 'success',
                        'pending' => 'warning',
                        'canceled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'paid', 'success' => 'Оплачено',
                        'pending' => 'Ожидает',
                        'canceled' => 'Отменено',
                        default => $state ?? 'Не указан',
                    })
                    ->alignment(\Filament\Support\Enums\Alignment::Center),

                // 6. ПРИМЕЧАНИЕ
                Tables\Columns\TextColumn::make('transaction_id')
                    ->label('Примечание (Банк)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(30)
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Фильтр по курсу')
                    ->relationship('course', 'title'),

                // Кто оплатил конкретный блок (напр. 2-й). Комбинируется с
                // фильтром по курсу выше. Включает поблочные покупки, чей
                // диапазон содержит блок, И покупателей всего курса (full).
                Tables\Filters\Filter::make('paid_block')
                    ->label('Оплаченный блок')
                    ->form([
                        Forms\Components\TextInput::make('block')
                            ->label('Блок №')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('напр. 2')
                            ->helperText('Для курса используйте «Фильтр по курсу» выше'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when($data['block'] ?? null, function ($q, $block) {
                            $n = (int) $block;

                            $q->whereIn('status', ['paid', 'success'])
                                ->where(function ($q2) use ($n) {
                                    $q2->where('tariff', 'full')              // весь курс
                                        ->orWhere('tariff', 'block_'.$n)      // подстраховка для строк без диапазона
                                        ->orWhere(fn ($q3) => $q3             // диапазон блоков содержит N
                                            ->whereNotNull('start_block')
                                            ->where('start_block', '<=', $n)
                                            ->where(fn ($q4) => $q4
                                                ->where('end_block', '>=', $n)
                                                ->orWhere(fn ($q5) => $q5
                                                    ->whereNull('end_block')
                                                    ->where('start_block', $n))));
                                });
                        });
                    })
                    ->indicateUsing(function (array $data): array {
                        if (! ($data['block'] ?? null)) {
                            return [];
                        }

                        return ['Оплачен блок №'.$data['block']];
                    }),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Фильтр по статусу')
                    ->options([
                        'pending' => 'Ожидает оплаты',
                        'paid' => 'Оплачено',
                        'canceled' => 'Отменено',
                    ]),

                Tables\Filters\TernaryFilter::make('is_deposit')
                    ->label('Только брони (депозиты)')
                    ->placeholder('Все транзакции')
                    ->trueLabel('Только брони')
                    ->falseLabel('Без броней')
                    ->queries(
                        true: fn ($query) => $query->where('tariff', 'deposit'),
                        false: fn ($query) => $query->where('tariff', '!=', 'deposit'),
                        blank: fn ($query) => $query,
                    ),

                Tables\Filters\Filter::make('created_at')
                    ->label('Период')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('С даты')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        Forms\Components\DatePicker::make('until')
                            ->label('По дату')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn ($q, $date) => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn ($q, $date) => $q->whereDate('created_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'С '.\Illuminate\Support\Carbon::parse($data['from'])->format('d.m.Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'По '.\Illuminate\Support\Carbon::parse($data['until'])->format('d.m.Y');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
