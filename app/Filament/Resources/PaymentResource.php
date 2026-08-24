<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Jobs\SendTelegramMessageJob;
use App\Mail\DepositTransferredMail;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Services\CuratorNotifier;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

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
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT);
    }

    public static function canCreate(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT);
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT);
    }

    /**
     * Месяцы для override начисления ЗП: от 12 вперёд (предоплата) до 30 назад
     * (просрочка/исторические правки), формат YYYY-MM => «Июнь 2026».
     *
     * @return array<string, string>
     */
    public static function salaryRecognitionMonthOptions(): array
    {
        $options = [];
        $cursor = now()->startOfMonth()->addMonths(12);
        for ($i = 0; $i < 43; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->translatedFormat('F Y');
            $cursor->subMonth();
        }

        return $options;
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
                                'salary_payout' => '👨‍🏫 Выплата преподавателю',
                            ];

                            for ($i = 1; $i <= 100; $i++) {
                                $startLesson = ($i - 1) * 4 + 1;
                                $endLesson = $i * 4;
                                $options["block_{$i}"] = "Блок {$i} (Занятия {$startLesson}-{$endLesson})";
                                // Половины блока — тот же формат ключа, что у витрины
                                // (Tariff::accessKey() → 'block_N_hH'). Доступ открывается
                                // урокам с lesson.block_half = H внутри блока N.
                                $options["block_{$i}_h1"] = "Блок {$i} — 1-я половина";
                                $options["block_{$i}_h2"] = "Блок {$i} — 2-я половина";
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
                                ->helperText('Пусто, если курс куплен целиком')
                                // «По блок №» не может быть меньше «с блока №»: перевёрнутый
                                // диапазон молча разворачивался в accrual в range(min,max) и
                                // размазывал платёж не на те блоки (audit #3). Студенческие
                                // пути это уже валидируют; ручная админ-форма — нет.
                                ->rule(static fn (Forms\Get $get) => static function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    $start = $get('start_block');
                                    if (filled($start) && filled($value) && (int) $value < (int) $start) {
                                        $fail('«По блок №» не может быть меньше «Оплачен с блока №».');
                                    }
                                }),

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

                    // Справочная сумма в валюте — параллельно рублёвой, только для отчёта
                    // (колонка «Примечание» финансовой таблицы). В расчётах не участвует.
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\TextInput::make('foreign_amount')
                            ->label('Сумма в валюте')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Справочно для отчёта. В расчётах (ЗП, доступы, скидки) не участвует.'),

                        Forms\Components\Select::make('foreign_currency')
                            ->label('Валюта')
                            ->options([
                                'USD' => 'Доллары ($)',
                                'EUR' => 'Евро (€)',
                            ])
                            ->native(false)
                            ->placeholder('—'),
                    ]),

                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label('Дата платежа')
                            ->default(now())
                            ->required()
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d.m.Y H:i')
                            // Дата в будущем — всегда опечатка ручного ввода: две
                            // строки «Расход» пять месяцев висели в сентябре-2026
                            // (10.09 вместо 10.02, issue #953, H2008). Задним
                            // числом — можно, вперёд — нет.
                            ->maxDate(now()->endOfDay())
                            ->validationMessages(['before_or_equal' => 'Дата платежа не может быть в будущем — проверьте день и месяц.'])
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

                        Forms\Components\Select::make('payment_method')
                            ->label('Способ оплаты')
                            ->options([
                                'card' => 'Карта',
                                'sbp' => 'СБП',
                                'dolyame' => 'Долями',
                                'cash' => 'Наличные',
                            ])
                            ->native(false)
                            ->placeholder('Не задан (Точка проставит сама)')
                            ->helperText('Для наличных и прочих ручных проводок ставьте явно. Карта/СБП/Долями приходят с вебхука Точки — не затирайте, если уже стоят.'),

                        Forms\Components\TextInput::make('transaction_id')
                            ->label('ID транзакции (Банк / Расход)')
                            ->maxLength(255),
                    ]),

                    Forms\Components\Select::make('salary_recognition_month')
                        ->label('Месяц начисления ЗП (override)')
                        ->options(self::salaryRecognitionMonthOptions())
                        ->searchable()
                        ->placeholder('Авто — по периодам блоков')
                        ->helperText('Перекрывает авто-расчёт ЗП преподавателя: вся сумма попадёт в выбранный месяц. Пусто = авто-раскладка по оплаченным блокам.')
                        // Внутреннее ЗП-поле: менеджеру/бухгалтеру-ассистенту не видно
                        // (скрытое поле не дегидрируется — существующее значение при
                        // сохранении не затирается).
                        ->visible(fn (): bool => RoleGate::adminOnly()),

                    // Возврат за конкретный платёж (H352, модель «отдельный Расход»).
                    // На «Расход»-возврате указывает исходную оплату — её признание
                    // выручки усечётся по месяц возврата (флаг revenue.reverse_
                    // unrecognized_on_refund), отложенная выручка вычтет возвращённое.
                    Forms\Components\Select::make('refund_of_payment_id')
                        ->label('Возврат за платёж (начисление)')
                        ->placeholder('Не возврат — признание не трогаем')
                        ->helperText('Только для «Расход»-возврата: исходная оплата, чью выручку усечь по месяц возврата. Пусто = обычный расход.')
                        ->searchable()
                        ->options(function ($get): array {
                            return Payment::query()
                                ->when($get('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
                                ->whereIn('status', Payment::PAID_STATUSES)
                                ->where('amount', '>', 0)
                                ->whereNull('refund_of_payment_id')
                                ->latest('created_at')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Payment $p): array => [
                                    $p->id => '#'.$p->id.' · '.number_format((float) $p->amount, 0, '.', ' ').' ₽ · '.$p->tariff.' · '.optional($p->created_at)->format('Y-m-d'),
                                ])
                                ->all();
                        })
                        // Money-critical: только админ (как salary_recognition_month).
                        ->visible(fn (): bool => RoleGate::adminOnly()),
                ]),

                // Прямой платёж на ЛИЧНЫЙ счёт преподавателя (минуя кассу школы).
                // Такой платёж НЕ образует выручку курса (иначе двойной счёт) и
                // авто-зачитывается в гонорар по номиналу в валюте выплаты препода.
                Forms\Components\Section::make('Прямой платёж преподавателю')
                    ->description('Деньги пришли напрямую на личный счёт преподавателя, минуя кассу школы. Зачтётся в его гонорар по номиналу.')
                    ->collapsed()
                    // Салярные/выплатные поля (received_account, received_by_teacher_id,
                    // payer_note) — только для админа. Менеджер-ассистент их не видит и
                    // не редактирует (money-critical trust floor, H222 D4). Скрытая
                    // секция не дегидрируется — существующие значения сохраняются.
                    ->visible(fn (): bool => RoleGate::adminOnly())
                    ->schema([
                        Forms\Components\Select::make('received_account')
                            ->label('Куда пришли деньги')
                            ->options([
                                Payment::RECEIVED_SCHOOL => 'Касса школы (обычный платёж)',
                                Payment::RECEIVED_TEACHER => 'Личный счёт преподавателя',
                            ])
                            ->default(Payment::RECEIVED_SCHOOL)
                            ->required()
                            ->native(false)
                            ->live(),

                        Forms\Components\Select::make('received_by_teacher_id')
                            ->label('Преподаватель-получатель')
                            ->relationship('receivedByTeacher', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visible(fn (Forms\Get $get) => $get('received_account') === Payment::RECEIVED_TEACHER)
                            ->required(fn (Forms\Get $get) => $get('received_account') === Payment::RECEIVED_TEACHER)
                            ->helperText('Валюта платежа (поле «Валюта» выше) должна совпадать с валютой выплаты этого преподавателя — иначе зачёт не сойдётся.')
                            // Инвариант валюты: foreign_currency платежа == payout_currency преподавателя.
                            ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if ($get('received_account') !== Payment::RECEIVED_TEACHER || empty($value)) {
                                    return;
                                }
                                $teacher = Teacher::find($value);
                                if ($teacher === null) {
                                    return;
                                }
                                $payoutCurrency = $teacher->payout_currency;
                                $paymentCurrency = $get('foreign_currency');
                                if (empty($payoutCurrency)) {
                                    $fail('У преподавателя не задана валюта выплаты (payout_currency) — зачёт по номиналу невозможен.');
                                } elseif ($paymentCurrency !== $payoutCurrency) {
                                    $fail("Валюта платежа ({$paymentCurrency}) не совпадает с валютой выплаты преподавателя ({$payoutCurrency}).");
                                }
                            }),

                        Forms\Components\TextInput::make('payer_note')
                            ->label('Плательщик / посредник')
                            ->maxLength(255)
                            ->placeholder('Напр.: за студента X заплатил Y, чек №…')
                            ->visible(fn (Forms\Get $get) => $get('received_account') === Payment::RECEIVED_TEACHER)
                            ->helperText('Кто фактически заплатил и через кого. Дата чека — поле «Дата платежа»; сумма в валюте — поля «Сумма в валюте»/«Валюта».'),
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
                    ->weight(FontWeight::Bold)
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
                        } elseif ($record->tariff === 'deposit' && (float) ($record->consumed_amount ?? 0) > 0) {
                            $label .= ' · зачтено частично: '.number_format((float) $record->consumed_amount, 0, ',', ' ').' ₽';
                        }

                        return $label;
                    }),

                // 4. СУММА
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB', locale: 'ru')
                    ->sortable()
                    ->weight(FontWeight::ExtraBold)
                    ->color(fn (Payment $record) => $record->amount < 0 ? 'danger' : ($record->status === 'paid' ? 'success' : 'gray'))
                    ->alignment(Alignment::End),

                // Пометка «по скидке»: бейдж «-10%» / «-1000 ₽», если платёж со скидкой.
                Tables\Columns\TextColumn::make('discount')
                    ->label('Скидка')
                    ->badge()
                    ->color('success')
                    ->getStateUsing(fn (Payment $record): ?string => $record->discountLabel() ?: null)
                    ->alignment(Alignment::Center),

                // Применённый промокод (если был). Скрыто по умолчанию — включается тумблером колонок.
                Tables\Columns\TextColumn::make('promoCode.code')
                    ->label('Промокод')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // 5. СТАТУС
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (?string $state): string => Payment::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => Payment::statusLabel($state))
                    ->alignment(Alignment::Center),

                // Способ оплаты из вебхука Точки (H226): card/sbp/dolyame. Пусто —
                // ручной платёж, PayPal или вебхук до появления поля; такие в
                // юнит-экономике считаются вилкой эквайринга.
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Способ')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'card' => 'info',
                        'sbp' => 'success',
                        'dolyame' => 'warning',
                        'cash' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'card' => 'Карта',
                        'sbp' => 'СБП',
                        'dolyame' => 'Долями',
                        'cash' => 'Наличные',
                        default => (string) $state,
                    })
                    ->placeholder('—')
                    ->alignment(Alignment::Center)
                    ->toggleable(),

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

                            $q->paid()
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

                // Способ оплаты Точки; «Не определён» = NULL (ручные платежи,
                // PayPal, старые вебхуки) — их эквайринг в юнит-экономике вилка.
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Способ оплаты')
                    ->options([
                        'card' => 'Карта',
                        'sbp' => 'СБП',
                        'dolyame' => 'Долями (рассрочка)',
                        'cash' => 'Наличные',
                        'unknown' => 'Не определён',
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $value) => $value === 'unknown'
                            ? $q->whereNull('payment_method')
                            : $q->where('payment_method', $value),
                    )),

                // Валютные заявки студентов (PayPal), ожидающие ручной сверки.
                Tables\Filters\Filter::make('paypal_pending')
                    ->label('Заявки PayPal на проверке')
                    ->query(fn ($query) => $query->paypalPending())
                    ->toggle(),

                // Авто-доверенные PayPal-заявки своих учеников (ruling 22-08-2026):
                // доступ выдан сразу, здесь — очередь ВЫБОРОЧНОЙ сверки пост-фактум.
                // Просмотренные (verified_at) из очереди исчезают.
                Tables\Filters\Filter::make('paypal_unverified')
                    ->label('PayPal: без сверки')
                    ->query(fn ($query) => $query->paypalUnverified())
                    ->toggle(),

                Tables\Filters\Filter::make('invoice_pending')
                    ->label('Счета юрлиц на проверке')
                    ->query(fn ($query) => $query->invoicePending())
                    ->toggle(),

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
                            $indicators[] = 'С '.Carbon::parse($data['from'])->format('d.m.Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'По '.Carbon::parse($data['until'])->format('d.m.Y');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                // Подтвердить PayPal-заявку после сверки платежа: перевод в paid
                // запускает штатную Payment::booted() → доступ/письма/прана.
                Tables\Actions\Action::make('confirmPaypal')
                    ->label('Подтвердить PayPal')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->isPaypal() && $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Подтвердить оплату через PayPal')
                    ->modalDescription(fn (Payment $record): string => 'Сверьте в личном PayPal: с '
                        .($record->claimMeta('paypal_payer') ?: '—')
                        .', дата '.($record->claimMeta('paid_on') ?: '—')
                        .', сумма '.($record->foreignAmountLabel() ?: '—')
                        .'. После подтверждения студенту откроется доступ и (для новых аккаунтов) уйдёт пароль на email.')
                    ->action(fn (Payment $record) => $record->update(['status' => 'paid'])),

                // Выборочная сверка авто-доверенной заявки: платеж нашли в личном
                // PayPal — снимаем с очереди «без сверки» (verified_at).
                Tables\Actions\Action::make('markPaypalVerified')
                    ->label('Сверка пройдена')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->isAutoTrustedPaypal() && $record->claimMeta('verified_at') === null)
                    ->requiresConfirmation()
                    ->modalHeading('Сверка пройдена')
                    ->modalDescription(fn (Payment $record): string => 'Платеж найден в личном PayPal: с '
                        .($record->claimMeta('paypal_payer') ?: '—')
                        .', дата '.($record->claimMeta('paid_on') ?: '—')
                        .', сумма '.($record->foreignAmountLabel() ?: '—')
                        .'. Заявка уйдет из очереди «PayPal: без сверки».')
                    ->action(fn (Payment $record) => $record->markPaypalVerified()),

                // Платеж так и не нашелся: отзываем авто-доверие. canceled на paid
                // запускает штатный откат — доступ закрывается, финансы пересчитываются.
                Tables\Actions\Action::make('rejectPaypalClaim')
                    ->label('Нет платежа — отменить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Payment $record) => $record->isAutoTrustedPaypal()
                        && in_array($record->status, Payment::PAID_STATUSES, true)
                        && $record->claimMeta('verified_at') === null)
                    ->requiresConfirmation()
                    ->modalHeading('Отменить заявку без подтверждения')
                    ->modalDescription('Платеж не найден в личном PayPal. Доступ будет отозван, запись в финансах отменена. Студенту стоит написать, почему доступ закрылся.')
                    ->action(fn (Payment $record) => $record->update(['status' => 'canceled'])),

                Tables\Actions\Action::make('confirmInvoice')
                    ->label('Подтвердить счет')
                    ->icon('heroicon-o-building-office-2')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->isCompanyInvoice() && $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Подтвердить оплату по счету')
                    ->modalDescription(fn (Payment $record): string => 'Сверьте поступление: '
                        .($record->claimMeta('company_name') ?: '—')
                        .', ИНН '.($record->claimMeta('inn') ?: '—')
                        .', '.$record->invoiceNumber()
                        .', '.number_format((float) $record->amount, 0, '.', ' ').' ₽. '
                        .'После подтверждения откроется доступ.')
                    ->action(fn (Payment $record) => $record->update(['status' => 'paid'])),

                Tables\Actions\Action::make('viewInvoice')
                    ->label('Счет')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (Payment $record) => $record->isCompanyInvoice())
                    ->url(fn (Payment $record) => route('invoice.print', $record), shouldOpenInNewTab: true),

                // Перенести оплаченную незачтённую бронь (депозит) на другой курс:
                // студент передумал и хочет учиться на другом курсе. Зачёт депозита
                // жёстко привязан к course_id (Tariff::prepaidCreditForUser /
                // Payment::consumeDepositsForCourse), поэтому перенос = смена course_id.
                // Меняем ТОЛЬКО курс — сумма/статус/зачёт не трогаются, доступ депозит
                // не выдавал; повторных писем брони нет (fireOnPaid висит на смене
                // status). Аудит course_id и sheet-sync — автоматом. Только админ.
                Tables\Actions\Action::make('transferDeposit')
                    ->label('Перенести бронь')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->visible(fn (Payment $record): bool => RoleGate::adminOnly()
                        && $record->isDeposit()
                        && in_array($record->status, Payment::PAID_STATUSES, true)
                        && $record->deposit_consumed_at === null)
                    ->modalHeading('Перенести бронь на другой курс')
                    ->modalDescription('Оплаченная сумма брони зачтётся при оплате выбранного курса. Курс текущей брони будет заменён; доступ к урокам бронь не открывала.')
                    ->form([
                        Forms\Components\Select::make('new_course_id')
                            ->label('Новый курс')
                            ->options(fn (Payment $record): array => Course::query()
                                ->where('id', '!=', $record->course_id)
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        Forms\Components\Toggle::make('notify_student')
                            ->label('Уведомить студента (e-mail + Telegram)')
                            ->default(true),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        $from = $record->course;
                        $to = Course::find($data['new_course_id']);
                        if (! $to) {
                            Notification::make()->title('Курс не найден')->danger()->send();

                            return;
                        }

                        // Единственное изменение — курс брони. Аудит (PaymentAuditObserver),
                        // blame и Google-Sheet ре-синк срабатывают автоматически.
                        $record->update(['course_id' => $to->id]);

                        if (($data['notify_student'] ?? true) && $record->user_id) {
                            if ($record->user?->email && $from) {
                                Mail::to($record->user->email)
                                    ->send(new DepositTransferredMail($record->user, $from, $to));
                            }

                            $text = "🔄 <b>Бронь перенесена</b>\n\n"
                                ."Ваша предоплата перенесена на курс <b>«{$to->title}»</b>. "
                                .'Сумма зачтётся при оплате.'
                                ."\n\n<a href='".url('/login')."'>Личный кабинет</a>";
                            SendTelegramMessageJob::dispatch($record->user_id, $text);
                        }

                        if ($from) {
                            app(CuratorNotifier::class)->depositTransferred($record, $from, $to);
                        }

                        Notification::make()
                            ->title('Бронь перенесена: «'.($from->title ?? '—').'» → «'.$to->title.'»')
                            ->success()
                            ->send();
                    }),

                // Открыть приложенный чек/скриншот (приватный диск, только персонал).
                Tables\Actions\Action::make('viewProof')
                    ->label('Чек')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->visible(fn (Payment $record) => (bool) $record->proof_path)
                    ->url(fn (Payment $record) => $record->proof_path ? route('paypal.proof', $record) : null, shouldOpenInNewTab: true),

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
        return [
            PaymentResource\RelationManagers\AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            // Полная страница правки платежа — несёт read-only таб «История
            // изменений» (аудит). Создание по-прежнему через модалку списка.
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}
