<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\MarketingSettingResource\Pages;
use App\Models\MarketingSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MarketingSettingResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = MarketingSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-8-tooth';

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Глобальные настройки';

    protected static ?string $modelLabel = 'Настройки';

    protected static ?string $pluralModelLabel = 'Настройки маркетинга';

    public static function canCreate(): bool
    {
        return MarketingSetting::count() === 0;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // --- БЛОК: Зарплаты преподавателей ---
                Forms\Components\Section::make('💸 Зарплаты преподавателей')
                    ->description('Опорный процент для сравнения «фикс vs процент» в калькуляторе выплаты по блоку. Подставляется по умолчанию, на месте редактируется.')
                    ->schema([
                        Forms\Components\TextInput::make('reference_teacher_percent')
                            ->label('Опорный % от выручки')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->default(30)
                            ->helperText('Гипотетическая ставка процента: с ней сравнивается фиксированная оплата.'),
                    ]),

                // --- БЛОК: Техобслуживание кабинета ---
                Forms\Components\Section::make('🛠 Техобслуживание кабинета')
                    ->description('Студенты увидят заглушку. Админка, редактор, вебхуки и витрина продолжают работать. Применяется сразу после сохранения.')
                    ->schema([
                        Forms\Components\Toggle::make('student_maintenance_enabled')
                            ->label('Кабинет на техобслуживании')
                            ->helperText('Обычные студенты получат страницу «техобслуживание». Админы проходят всегда.')
                            ->default(false),

                        Forms\Components\Textarea::make('student_maintenance_message')
                            ->label('Текст заглушки')
                            ->rows(3)
                            ->placeholder('Скоро вернёмся 🙏 Идут технические работы — кабинет ненадолго недоступен.')
                            ->helperText('Если пусто — покажем текст по умолчанию.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('student_maintenance_secret')
                            ->label('Токен обхода (для QA)')
                            ->helperText('Ссылка для проверки под студентом: /maintenance-bypass/{токен}. Пусто = обход по ссылке выключен.')
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('genSecret')
                                    ->icon('heroicon-m-arrow-path')
                                    ->tooltip('Сгенерировать токен')
                                    ->action(fn (Forms\Set $set) => $set('student_maintenance_secret', \Illuminate\Support\Str::random(32)))
                            ),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('🤖 Боты-кураторы в кабинете')
                    ->description('Показывать ли студентам блоки привязки ботов на главной кабинета. После привязки бот отвечает на вопросы по обучению (ИИ-куратор). Тумблеры раздельные — можно включить только тот мессенджер, что уже настроен.')
                    ->schema([
                        Forms\Components\Toggle::make('student_telegram_bot_enabled')
                            ->label('Показывать блок привязки Telegram-бота')
                            ->helperText('Выключено — блок Telegram скрыт. Включите, когда студбот настроен и вебхук выставлен.')
                            ->default(false),
                        Forms\Components\Toggle::make('student_vk_bot_enabled')
                            ->label('Показывать блок привязки ВК-бота')
                            ->helperText('Выключено — блок ВКонтакте скрыт. Включите, когда ВК-бот настроен и готов к работе.')
                            ->default(false),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('🔔 Авто-уведомления студентам')
                    ->description('Проактивные пуши студентам в Telegram/VK (по расписанию). Не влияют на ответы ИИ-куратора и личные уведомления об оплатах/доступах — только на массовые напоминания ниже.')
                    ->schema([
                        Forms\Components\Toggle::make('payment_reminders_enabled')
                            ->label('Напоминания о сроках оплаты')
                            ->helperText('Студентам, у кого завтра срок по обещанию/рассрочке (promises:remind-tomorrow). Время — в поле ниже. Выключено — не шлём.')
                            ->default(true),
                        Forms\Components\TimePicker::make('payment_reminder_time')
                            ->label('Время рассылки об оплатах (МСК)')
                            ->seconds(false)
                            ->format('H:i')
                            ->displayFormat('H:i')
                            ->default('09:00')
                            ->helperText('Час ежедневной рассылки напоминаний об оплате.'),
                        Forms\Components\Toggle::make('class_reminders_enabled')
                            ->label('Напоминания о занятиях')
                            ->helperText('Студентам группы/курса со ссылкой перед занятием из расписания (classes:remind-upcoming). За сколько минут — в поле ниже. Выключено — не шлём.')
                            ->default(true),
                        Forms\Components\TextInput::make('class_reminder_lead_minutes')
                            ->label('Напоминать о занятии за, мин')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->suffix('мин')
                            ->default(60)
                            ->helperText('За сколько минут до начала занятия слать пуш (1–1440).'),

                        Forms\Components\Toggle::make('absent_notify_enabled')
                            ->label('Уведомлять пропустивших занятие')
                            ->helperText('После занятия писать в TG/VK тем, кто не пришёл и не переходил по ссылке (classes:notify-absent). Задержка — в поле ниже. По умолчанию выключено.')
                            ->default(false),
                        Forms\Components\TextInput::make('absent_notify_delay_minutes')
                            ->label('Уведомлять об отсутствии через, мин после конца')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1440)
                            ->suffix('мин')
                            ->default(30)
                            ->helperText('Через сколько минут после конца занятия слать «вы пропустили» (0–1440).'),

                        Forms\Components\Toggle::make('debt_reminders_enabled')
                            ->label('Напоминания должникам')
                            ->helperText('Авто-рассылка должникам (просрочка / не продлил / блок скоро начнётся) по TG/VK/email. Каждому — не чаще раза в N дней. Выключено — не шлём.')
                            ->default(false)
                            ->live(),
                        Forms\Components\TextInput::make('debt_reminder_lead_days')
                            ->label('Напоминать о блоке за, дней до начала')
                            ->numeric()->minValue(0)->maxValue(60)->suffix('дн')->default(7)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('debt_reminders_enabled')),
                        Forms\Components\TextInput::make('debt_reminder_cadence_days')
                            ->label('Не чаще раза в, дней')
                            ->numeric()->minValue(1)->maxValue(60)->suffix('дн')->default(7)
                            ->helperText('Анти-спам: повтор одному студенту по курсу не раньше.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('debt_reminders_enabled')),
                        Forms\Components\Toggle::make('debt_reminder_to_telegram')->label('Канал: Telegram')->default(true)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('debt_reminders_enabled')),
                        Forms\Components\Toggle::make('debt_reminder_to_vk')->label('Канал: VK')->default(true)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('debt_reminders_enabled')),
                        Forms\Components\Toggle::make('debt_reminder_to_email')->label('Канал: Email')->default(true)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('debt_reminders_enabled')),
                        Forms\Components\TextInput::make('debt_reminder_subject')
                            ->label('Тема письма (email)')
                            ->placeholder(\App\Services\DebtorReminderDispatcher::DEFAULT_SUBJECT)
                            ->helperText('Плейсхолдеры: {name}, {course}, {block}, {pay_link}.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('debt_reminders_enabled')),
                        Forms\Components\Textarea::make('debt_reminder_text')
                            ->label('Текст напоминания')
                            ->rows(6)
                            ->placeholder(\App\Services\DebtorReminderDispatcher::DEFAULT_TEXT)
                            ->helperText('Пусто = текст по умолчанию. Плейсхолдеры: {name}, {course}, {block}, {pay_link}.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('debt_reminders_enabled')),
                    ])
                    ->collapsible(),

                // --- БЛОК 1: Главный рубильник ---
                Forms\Components\Section::make('Общий статус лояльности')
                    ->schema([
                        Forms\Components\Toggle::make('is_loyalty_active')
                            ->label('Программа лояльности включена')
                            ->helperText('Если выключить, никакие скидки из блоков ниже применяться не будут.')
                            ->default(false)
                            ->live(), // Делаем переключатель "живым", чтобы скрывать/показывать блоки ниже
                    ]),

                // --- БЛОК: Предоплата за бронь курса ---
                Forms\Components\Section::make('📌 Предоплата за бронь курса')
                    ->description('Глобальный рубильник кнопки «Забронировать». Сумма предоплаты задаётся отдельно на каждом курсе (поле «Сумма депозита» в карточке курса).')
                    ->schema([
                        Forms\Components\Toggle::make('deposit_enabled')
                            ->label('Включить кнопку «Забронировать»')
                            ->helperText('Если выключено — кнопка не появляется на витрине ни для одного курса. Если включено, кнопка показывается только у курсов с заданной суммой депозита.')
                            ->default(false),
                    ]),

                // --- БЛОК 2: Пакетные скидки (Единовременная покупка) ---
                Forms\Components\Section::make('Скидки за объем в чеке (Пакетные)')
                    ->description('Применяются при единовременной покупке нескольких факультативов (при полной оплате).')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('bundle_2_discount')
                                ->label('Скидка за 2 курса (%)')
                                ->numeric()
                                ->default(10)
                                ->minValue(0)->maxValue(100),

                            Forms\Components\TextInput::make('bundle_3_discount')
                                ->label('Скидка за 3 и более курсов (%)')
                                ->numeric()
                                ->default(15)
                                ->minValue(0)->maxValue(100),
                        ]),
                    ])
                    ->visible(fn (Forms\Get $get) => $get('is_loyalty_active')), // Показываем только если лояльность включена

                // --- БЛОК 3: Оптовики (Накопительные скидки за прошлый год) ---
                Forms\Components\Section::make('Накопительные скидки ("Оптовики")')
                    ->description('Скидки на факультативы, основанные на количестве купленных курсов за прошлый календарный год. Работают даже при поблочной оплате.')
                    ->schema([
                        // Мелкий опт
                        Forms\Components\Fieldset::make('Мелкий опт')
                            ->schema([
                                Forms\Components\TextInput::make('wholesale_small_threshold')
                                    ->label('Порог курсов (от)')
                                    ->numeric()
                                    ->default(5)
                                    ->minValue(1),

                                Forms\Components\TextInput::make('wholesale_small_discount')
                                    ->label('Скидка (%)')
                                    ->numeric()
                                    ->default(10)
                                    ->minValue(0)->maxValue(100),
                            ])->columns(2),

                        // Крупный опт
                        Forms\Components\Fieldset::make('Крупный опт')
                            ->schema([
                                Forms\Components\TextInput::make('wholesale_large_threshold')
                                    ->label('Порог курсов (от)')
                                    ->numeric()
                                    ->default(10)
                                    ->minValue(1),

                                Forms\Components\TextInput::make('wholesale_large_discount')
                                    ->label('Скидка (%)')
                                    ->numeric()
                                    ->default(15)
                                    ->minValue(0)->maxValue(100),
                            ])->columns(2),

                    ])
                    ->visible(fn (Forms\Get $get) => $get('is_loyalty_active')),

                // ==========================================
                // ПРАНА (геймификация)
                // ==========================================
                Forms\Components\Section::make('🪷 Прана (геймификация)')
                    ->description('Виртуальная валюта студентов. Накапливается за активность, тратится на покупку курсов.')
                    ->schema([
                        Forms\Components\Toggle::make('is_prana_active')
                            ->label('Прана включена')
                            ->helperText('Если выключить: бейдж и вкладка скроются у студентов, начисления остановятся, на checkout пропадёт слайдер списания. Уже накопленные балансы сохранятся — при повторном включении продолжат работать.')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger')
                            ->live(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('prana_rate')
                                    ->label('Курс конвертации')
                                    ->helperText('Сколько праны = 1 ₽ скидки. По умолчанию 10 (значит 100 праны = 10 ₽).')
                                    ->numeric()
                                    ->default(10)
                                    ->minValue(1)
                                    ->maxValue(1000)
                                    ->required(),

                                Forms\Components\TextInput::make('prana_max_share_percent')
                                    ->label('Макс. доля цены, %')
                                    ->helperText('Какую долю стоимости курса можно покрыть праной. По умолчанию 30%.')
                                    ->numeric()
                                    ->default(30)
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->required(),
                            ])
                            ->visible(fn (Forms\Get $get) => $get('is_prana_active')),

                        Forms\Components\Fieldset::make('Сколько праны начисляется за активность')
                            ->schema([
                                Forms\Components\TextInput::make('prana_reward_lesson_complete')
                                    ->label('За завершённый урок')
                                    ->numeric()
                                    ->default(10)
                                    ->minValue(0)
                                    ->suffix('🪷'),

                                Forms\Components\TextInput::make('prana_reward_course_complete')
                                    ->label('За пройденный курс целиком')
                                    ->numeric()
                                    ->default(500)
                                    ->minValue(0)
                                    ->suffix('🪷'),

                                Forms\Components\TextInput::make('prana_reward_open_lesson_view')
                                    ->label('За просмотр открытого урока / вебинара')
                                    ->numeric()
                                    ->default(20)
                                    ->minValue(0)
                                    ->suffix('🪷'),

                                Forms\Components\TextInput::make('prana_reward_daily_login')
                                    ->label('Ежедневный вход в кабинет')
                                    ->numeric()
                                    ->default(5)
                                    ->minValue(0)
                                    ->suffix('🪷'),

                                Forms\Components\TextInput::make('prana_reward_payment_success')
                                    ->label('За успешную оплату')
                                    ->numeric()
                                    ->default(50)
                                    ->minValue(0)
                                    ->suffix('🪷'),
                            ])
                            ->columns(2)
                            ->visible(fn (Forms\Get $get) => $get('is_prana_active')),
                    ]),

                // ==========================================
                // БОТЫ ДЛЯ LEAD-MAGNET
                // ==========================================
                Forms\Components\Section::make('🤖 Боты для lead-magnet')
                    ->description('Отдельные боты, через которые доставляется файл-подарок после заявки. Токены шифруются в БД.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Select::make('magnet_delivery_mode')
                            ->label('Режим доставки магнита')
                            ->options([
                                'redirect' => 'Авто-редирект (юзер сразу попадает в бота)',
                                'page' => 'Страница «Спасибо» с кнопками выбора канала',
                            ])
                            ->default('redirect')
                            ->helperText('redirect — максимальная конверсия. page — юзер сам выбирает канал.'),

                        Forms\Components\Fieldset::make('Telegram Bot')
                            ->schema([
                                Forms\Components\TextInput::make('tg_bot_username')
                                    ->label('Username бота (без @)')
                                    ->placeholder('samskrtam_magnet_bot')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('tg_bot_token')
                                    ->label('Bot Token')
                                    ->password()
                                    ->revealable()
                                    ->placeholder('Получить у @BotFather')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('tg_webhook_secret')
                                    ->label('Webhook Secret (случайная строка 32+ символов)')
                                    ->password()
                                    ->revealable()
                                    ->helperText('Сгенерировать: php artisan tinker → Str::random(48)')
                                    ->maxLength(64),
                            ])->columns(1),

                        Forms\Components\Fieldset::make('VK Сообщество')
                            ->schema([
                                Forms\Components\TextInput::make('vk_group_screen_name')
                                    ->label('Screen Name группы')
                                    ->placeholder('samskrtam_community')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('vk_group_id')
                                    ->label('ID группы (число)')
                                    ->placeholder('123456789')
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('vk_access_token')
                                    ->label('Access Token сообщества')
                                    ->password()
                                    ->revealable(),
                                Forms\Components\TextInput::make('vk_callback_secret')
                                    ->label('Callback Secret')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('vk_confirmation_code')
                                    ->label('Confirmation Code (из настроек Callback API)')
                                    ->helperText('Возьмите из VK → Управление сообществом → API → Callback API → Confirmation')
                                    ->maxLength(64),
                            ])->columns(2),

                        Forms\Components\Fieldset::make('Max Bot')
                            ->schema([
                                Forms\Components\TextInput::make('max_bot_username')
                                    ->label('Username бота (без @)')
                                    ->placeholder('samskrtam_magnet_bot')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('max_bot_token')
                                    ->label('Bot Token')
                                    ->password()
                                    ->revealable(),
                                Forms\Components\TextInput::make('max_webhook_secret')
                                    ->label('Webhook Secret')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(64),
                            ])->columns(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ToggleColumn::make('is_loyalty_active')
                    ->label('Лояльность включена'),

                Tables\Columns\TextColumn::make('bundle_3_discount')
                    ->label('Макс. пакетная')
                    ->formatStateUsing(fn ($state) => $state.' %'),

                Tables\Columns\TextColumn::make('wholesale_large_discount')
                    ->label('Макс. накопительная')
                    ->formatStateUsing(fn ($state) => $state.' %'),

                Tables\Columns\IconColumn::make('is_prana_active')
                    ->label('🪷 Прана')
                    ->boolean()
                    ->alignment('center'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketingSettings::route('/'),
            'create' => Pages\CreateMarketingSetting::route('/create'),
            'edit' => Pages\EditMarketingSetting::route('/{record}/edit'),
        ];
    }
}
