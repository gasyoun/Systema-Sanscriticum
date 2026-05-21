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
