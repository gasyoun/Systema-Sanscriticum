<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\CourseNoteBlockParser;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    // Современная иконка для бокового меню
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Студенты';

    protected static ?string $pluralModelLabel = 'Студенты';

    public static function canViewAny(): bool
    {
        // Бухгалтер видит студентов для сверки (read-only): создание/правка/
        // удаление остаются за админами — он не проходит adminOnly()/canEdit().
        return RoleGate::any(Roles::ADMIN, Roles::ACCOUNTANT);
    }

    public static function canCreate(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canView($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::ACCOUNTANT);
    }

    // Массовое удаление студентов — только админам. Без этого override Filament
    // отдаёт canDeleteAny()=true (нет UserPolicy), и DeleteBulkAction всплыл бы
    // у бухгалтера, который видит список (canViewAny), но править/удалять не должен.
    public static function canDeleteAny(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        // Супер-админ редактирует кого угодно. Обычный админ не может править
        // других супер-админов и админов — только обычных пользователей.
        if ($user->isSuperAdmin()) {
            return true;
        }
        // Свой собственный профиль — всегда можно (повышение роли всё равно
        // отрезано Rule::in на поле role в форме).
        if ($user->id === $record->id && $user->isAdminLike()) {
            return true;
        }
        if ($user->isAdmin()) {
            return ! in_array($record->role, Roles::adminLike(), true);
        }

        return false;
    }

    public static function canDelete($record): bool
    {
        return self::canEdit($record) && auth()->id() !== $record->id;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->description('Личные данные студента и параметры входа')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('curator_display_name')
                            ->label('Псевдоним куратора (виден студентам)')
                            ->maxLength(255)
                            ->placeholder('Напр.: куратор Маша')
                            ->helperText('Подставляется перед ответами студенту в чате. Пусто = настоящее имя.'),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255),

                        // --- НОВОЕ ПОЛЕ: Телефон ---
                        Forms\Components\TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),
                    ])->columns(2),

                // --- НОВЫЙ БЛОК: Статус и Примечания ---
                Forms\Components\Section::make('Дополнительная информация')
                    ->schema([
                        Forms\Components\Select::make('global_status')
                            ->label('Глобальный статус')
                            ->options([
                                'Обычный студент' => 'Обычный студент',
                                'Техподдержка' => 'Техподдержка',
                                'VIP' => 'VIP',
                                'Занимается бесплатно' => 'Занимается бесплатно',
                                'Бартер' => 'Бартер',
                            ])
                            ->default('Обычный студент')
                            ->required(),

                        // Read-only превью примечания со ссылками-кликами (ВК и пр.).
                        // Textarea ниже — для редактирования; здесь — для перехода по ссылкам.
                        Forms\Components\Placeholder::make('note_preview')
                            ->label('Примечание куратора')
                            ->columnSpanFull()
                            ->visible(fn (string $operation, ?User $record): bool => $operation !== 'create' && filled($record?->note))
                            ->content(fn (?User $record) => static::linkifyNote($record?->note)),

                        Forms\Components\Textarea::make('note')
                            ->label('Примечание куратора (редактирование)')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Обучение и Права')
                    ->schema([
                        Forms\Components\Select::make('groups')
                            ->label('Программы / Доступы')
                            ->multiple()
                            ->relationship('groups', 'name')
                            ->preload(),

                        Forms\Components\Select::make('role')
                            ->label('Роль в админке')
                            ->helperText('Без роли — обычный студент, без доступа в админку. Роль «Преподаватель» назначается через раздел «Преподаватели».')
                            ->options(function (?\Illuminate\Database\Eloquent\Model $record) {
                                $all = Roles::all();
                                if (! RoleGate::isSuperAdmin()) {
                                    // Бухгалтер имеет доступ к выплатам, которого нет у обычного
                                    // admin → выдавать эту роль может только супер-админ.
                                    unset($all[Roles::SUPER_ADMIN], $all[Roles::ADMIN], $all[Roles::ACCOUNTANT]);
                                }
                                // Роль преподавателя выдаётся только через TeacherResource.
                                // Если у записи она уже есть — оставляем её в списке как информационную.
                                if (! $record || $record->role !== Roles::TEACHER) {
                                    unset($all[Roles::TEACHER]);
                                } else {
                                    $all[Roles::TEACHER] = $all[Roles::TEACHER].' (управление в «Преподавателях»)';
                                }

                                return $all;
                            })
                            // Серверная защита от подмены значения через DevTools/POST.
                            ->rule(function (?\Illuminate\Database\Eloquent\Model $record) {
                                $allowed = RoleGate::isSuperAdmin()
                                    ? Roles::all()
                                    : array_diff_key(Roles::all(), array_flip([Roles::SUPER_ADMIN, Roles::ADMIN, Roles::ACCOUNTANT]));
                                // TEACHER нельзя выдать через эту форму ни админу, ни супер-админу.
                                unset($allowed[Roles::TEACHER]);
                                $keys = array_keys($allowed);
                                // Сохранять текущую роль записи всегда можно — иначе на edit'е
                                // существующего преподавателя или себя самого save валится.
                                if ($record && $record->role) {
                                    $keys[] = $record->role;
                                }

                                return \Illuminate\Validation\Rule::in([null, ...$keys]);
                            })
                            ->placeholder('— Студент —')
                            ->live()
                            ->visible(fn () => RoleGate::adminOnly()),

                        Forms\Components\Toggle::make('is_lecture_editor')
                            ->label('Редактор лекций')
                            ->helperText('Доступ к панели сборки лекций (без доступа в админку)')
                            ->onColor('success')
                            ->offColor('gray')
                            ->visible(fn () => RoleGate::isSuperAdmin()),
                    ])->columns(1),

                Forms\Components\Section::make('🪷 Прана')
                    ->description('Текущий баланс лояльности студента. Начисление и списание — кнопкой в списке студентов.')
                    ->schema([
                        Forms\Components\Placeholder::make('prana_balance_view')
                            ->label('Баланс')
                            ->content(fn (?\App\Models\User $record) => $record
                                ? number_format((int) $record->prana_balance, 0, '.', ' ').' праны'
                                : '—'),
                    ])
                    ->visible(fn (string $operation) => $operation !== 'create' && RoleGate::adminOnly())
                    ->columns(1),
            ]);
    }

    /**
     * Рендерит примечание куратора с кликабельными ссылками.
     * XSS-безопасно: сначала экранируем весь текст, затем вставляем только наши <a>.
     */
    protected static function linkifyNote(?string $note): \Illuminate\Support\HtmlString
    {
        $html = e((string) $note);

        // Полные URL (http/https).
        $html = preg_replace(
            '~(https?://[^\s<]+)~i',
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">$1</a>',
            $html
        );

        // Голые vk.com/... без схемы. Лукбехайнд не даёт повторно линковать
        // уже обёрнутый https://vk.com/... (перед vk стоит / или буква).
        $html = preg_replace(
            '~(?<![/\w])((?:www\.)?vk\.com/[^\s<]+)~i',
            '<a href="https://$1" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">$1</a>',
            $html
        );

        // Email → mailto. Раньше телеграма, чтобы @домен почты не утёк в t.me-ссылку.
        $html = preg_replace(
            '~(?<![\w@/])([A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)~',
            '<a href="mailto:$1" class="text-primary-600 underline">$1</a>',
            $html
        );

        // Telegram @handle → t.me. Лукбехайнд (?<![\w@/]) не трогает @ внутри почты
        // (там перед @ стоит буква) и уже обёрнутых ссылок.
        $html = preg_replace(
            '~(?<![\w@/])@([A-Za-z0-9_]{4,32})~',
            '<a href="https://t.me/$1" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">@$1</a>',
            $html
        );

        // Телефон → tel:. Разделители без точки/двоеточия, чтобы не цеплять даты
        // и штампы вроде «04.06.2026 14:30». Колбэк требует 10–15 цифр —
        // отсекает короткие числа, суммы и порядковые номера.
        $html = preg_replace_callback(
            '~(?<![\d\w>])(\+?\d[\d\s()\-]{8,}\d)~',
            function (array $m): string {
                $digits = preg_replace('/\D/', '', $m[1]);
                $len = strlen($digits);
                if ($len < 10 || $len > 15) {
                    return $m[1];
                }
                $tel = (str_starts_with($m[1], '+') ? '+' : '').$digits;

                return '<a href="tel:'.$tel.'" class="text-primary-600 underline">'.$m[1].'</a>';
            },
            $html
        );

        return new \Illuminate\Support\HtmlString(nl2br($html));
    }

    /**
     * Read-only карточка студента (режим просмотра).
     * Открывается по короткой ссылке /s/{id} — её вставляют в заметку Telegram-контакта.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Студент')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Имя')
                            ->weight('bold'),

                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable()
                            ->copyMessage('Email скопирован'),

                        TextEntry::make('phone')
                            ->label('Телефон')
                            ->icon('heroicon-m-phone')
                            ->copyable()
                            ->copyMessage('Телефон скопирован')
                            ->placeholder('—'),

                        TextEntry::make('global_status')
                            ->label('Статус')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'VIP' => 'warning',
                                'Техподдержка' => 'danger',
                                'Занимается бесплатно' => 'info',
                                'Бартер' => 'info',
                                default => 'success',
                            }),

                        TextEntry::make('role')
                            ->label('Роль')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => Roles::all()[$state] ?? '—')
                            ->color(fn (?string $state): string => match ($state) {
                                Roles::SUPER_ADMIN => 'danger',
                                Roles::ADMIN => 'warning',
                                Roles::TEACHER => 'info',
                                Roles::MANAGER => 'primary',
                                Roles::ACCOUNTANT => 'success',
                                default => 'gray',
                            })
                            ->visible(fn () => RoleGate::adminOnly()),

                        TextEntry::make('messengers')
                            ->label('Мессенджеры')
                            ->state(function (User $record): string {
                                $fmt = fn ($d): string => $d
                                    ? ' (с '.\Carbon\Carbon::parse($d)->format('d.m.Y').')'
                                    : '';
                                $tg = $record->telegram_id ? '✈ Telegram'.$fmt($record->telegram_connected_at) : '';
                                $vk = $record->vk_id ? '💬 VK'.$fmt($record->vk_connected_at) : '';
                                $parts = array_filter([$tg, $vk]);

                                return ! empty($parts) ? implode(' · ', $parts) : 'Нет мессенджеров';
                            }),
                    ]),

                InfoSection::make('Ссылка на карточку')
                    ->description('Для заметки в Telegram-контакте: по клику открывает эту карточку.')
                    ->schema([
                        TextEntry::make('card_short_link')
                            ->hiddenLabel()
                            ->state(fn (User $record): string => url('/u/'.$record->id))
                            ->copyable()
                            ->copyableState(fn (User $record): string => url('/u/'.$record->id))
                            ->copyMessage('Ссылка скопирована')
                            ->columnSpanFull(),
                    ]),

                InfoSection::make('Примечание куратора')
                    ->visible(fn (?User $record): bool => filled($record?->note))
                    ->schema([
                        TextEntry::make('note')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (?User $record) => static::linkifyNote($record?->note))
                            ->columnSpanFull(),
                    ]),

                InfoSection::make('Обучение и активность')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('groups.name')
                            ->label('Программы / Доступы')
                            ->badge()
                            ->placeholder('—'),

                        TextEntry::make('prana_balance')
                            ->label('🪷 Прана')
                            ->state(fn (?User $record) => $record
                                ? number_format((int) $record->prana_balance, 0, '.', ' ').' праны'
                                : '—')
                            ->visible(fn () => RoleGate::adminOnly()),

                        TextEntry::make('last_activity_at')
                            ->label('Последний визит')
                            ->formatStateUsing(fn ($state): string => $state === null
                                ? 'Никогда'
                                : \Carbon\Carbon::parse($state)->diffForHumans())
                            ->tooltip(fn ($state): ?string => $state
                                ? \Carbon\Carbon::parse($state)->translatedFormat('d.m.Y H:i:s')
                                : null),

                        TextEntry::make('activity_stats')
                            ->label('Статистика')
                            ->state(function (User $record): string {
                                $lessons = (int) $record->total_lessons_opened;
                                $seconds = (int) $record->total_time_spent;
                                $visits = (int) $record->login_count;

                                $hours = intdiv($seconds, 3600);
                                $mins = intdiv($seconds % 3600, 60);
                                $time = $hours > 0 ? "{$hours}ч {$mins}м" : "{$mins}м";

                                return "📚 {$lessons} · ⏱ {$time} · 🔑 {$visits}";
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // --- КОЛОНКА 1: СТУДЕНТ ---
                // name основное, email и id — подписи под ним
                Tables\Columns\TextColumn::make('name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn ($record) => $record->email.' · ID: '.$record->id)
                    ->wrap(),

                // --- КОЛОНКА 2: КОНТАКТЫ ---
                // Телефон + мелкие иконки TG/VK под ним
                Tables\Columns\TextColumn::make('phone')
                    ->label('Контакты')
                    ->copyable()
                    ->copyMessage('Телефон скопирован')
                    ->icon('heroicon-m-phone')
                    ->iconColor('gray')
                    ->placeholder('—')
                    ->description(function ($record): string {
                        $tg = $record->telegram_id ? '✈ TG' : '';
                        $vk = $record->vk_id ? '💬 VK' : '';
                        $parts = array_filter([$tg, $vk]);

                        return ! empty($parts) ? implode(' · ', $parts) : 'Нет мессенджеров';
                    }),

                // --- ССЫЛКА НА КАРТОЧКУ (копируется в заметку Telegram-контакта) ---
                Tables\Columns\TextColumn::make('card_link')
                    ->label('Ссылка')
                    ->state('🔗 Копировать')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyableState(fn (User $record): string => url('/u/'.$record->id))
                    ->copyMessage('Ссылка на карточку скопирована')
                    ->copyMessageDuration(1500)
                    ->toggleable(),

                // --- ПОДКЛЮЧЕНИЕ БОТА (скрыто по умолчанию; для сортировки/аналитики) ---
                Tables\Columns\TextColumn::make('telegram_connected_at')
                    ->label('Подключил TG')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): ?string => $state
                        ? \Carbon\Carbon::parse($state)->format('d.m.Y H:i')
                        : null),

                Tables\Columns\TextColumn::make('vk_connected_at')
                    ->label('Подключил VK')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): ?string => $state
                        ? \Carbon\Carbon::parse($state)->format('d.m.Y H:i')
                        : null),

                // --- КОЛОНКА 3: СТАТУС ---
                Tables\Columns\TextColumn::make('global_status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'VIP' => 'warning',
                        'Техподдержка' => 'danger',
                        'Занимается бесплатно' => 'info',
                        'Бартер' => 'info',
                        default => 'success',
                    })
                    ->searchable()
                    ->sortable(),

                // --- КОЛОНКА 4: АКТИВНОСТЬ ---
                // last_activity_at основное, мелкая подстрочная статистика под ним
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Последний визит')
                    ->sortable()
                    ->formatStateUsing(function ($state): string {
                        if ($state === null) {
                            return 'Никогда';
                        }

                        return \Carbon\Carbon::parse($state)->diffForHumans();
                    })
                    ->tooltip(fn ($state): ?string => $state
                        ? \Carbon\Carbon::parse($state)->translatedFormat('d.m.Y H:i:s')
                        : null)
                    ->color(function ($state): string {
                        if ($state === null) {
                            return 'gray';
                        }
                        $d = \Carbon\Carbon::parse($state);
                        if ($d->gt(now()->subMinutes(5))) {
                            return 'success';
                        }
                        if ($d->gt(now()->subHour())) {
                            return 'warning';
                        }
                        if ($d->gt(now()->subDays(7))) {
                            return 'gray';
                        }

                        return 'danger';
                    })
                    ->icon(function ($state): string {
                        if ($state === null) {
                            return 'heroicon-m-minus-circle';
                        }
                        $d = \Carbon\Carbon::parse($state);
                        if ($d->gt(now()->subMinutes(5))) {
                            return 'heroicon-m-signal';
                        }

                        return 'heroicon-m-clock';
                    })
                    ->weight('medium')
                    ->description(function ($record): string {
                        $lessons = (int) $record->total_lessons_opened;
                        $seconds = (int) $record->total_time_spent;
                        $visits = (int) $record->login_count;

                        $hours = intdiv($seconds, 3600);
                        $mins = intdiv($seconds % 3600, 60);
                        $time = $hours > 0 ? "{$hours}ч {$mins}м" : "{$mins}м";

                        return "📚 {$lessons} · ⏱ {$time} · 🔑 {$visits}";
                    }),

                // --- КОЛОНКА 5: РОЛЬ (видна админам и супер-админу) ---
                Tables\Columns\TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Roles::all()[$state] ?? '—')
                    ->color(fn (?string $state): string => match ($state) {
                        Roles::SUPER_ADMIN => 'danger',
                        Roles::ADMIN => 'warning',
                        Roles::TEACHER => 'info',
                        Roles::MANAGER => 'primary',
                        Roles::ACCOUNTANT => 'success',
                        default => 'gray',
                    })
                    ->alignment('center')
                    ->toggleable()
                    ->visible(fn () => RoleGate::adminOnly()),

                Tables\Columns\IconColumn::make('is_lecture_editor')
                    ->label('Ред. лекций')
                    ->boolean()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => RoleGate::isSuperAdmin()),

                Tables\Columns\IconColumn::make('wants_email_announcements')
                    ->label('Анонсы на email')
                    ->boolean()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('prana_balance')
                    ->label('Прана')
                    ->badge()
                    ->color(fn (?int $state) => match (true) {
                        $state === null || $state === 0 => 'gray',
                        $state >= 1000 => 'warning',
                        default => 'success',
                    })
                    ->icon('heroicon-m-sparkles')
                    ->formatStateUsing(fn (?int $state) => number_format((int) $state, 0, '.', ' '))
                    ->sortable()
                    ->alignment('center')
                    ->toggleable()
                    ->visible(fn () => RoleGate::adminOnly()),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->filters([

                // --- НОВЫЙ ФИЛЬТР: Наличие настоящего email ---
                Tables\Filters\TernaryFilter::make('has_real_email')
                    ->label('Email')
                    ->placeholder('Все студенты')
                    ->trueLabel('Только с настоящим email')
                    ->falseLabel('Только с заглушкой @no-email.com')
                    ->queries(
                        true: fn (Builder $query) => $query->where('email', 'not like', '%@no-email.com'),
                        false: fn (Builder $query) => $query->where('email', 'like', '%@no-email.com'),
                        blank: fn (Builder $query) => $query, // показываем всех
                    ),

                // --- НОВЫЙ ФИЛЬТР: Отправлено ли письмо с доступом ---
                Tables\Filters\TernaryFilter::make('access_sent')
                    ->label('Письмо с доступом')
                    ->placeholder('Все студенты')
                    ->trueLabel('Доступ уже отправлен')
                    ->falseLabel('Доступ ещё не отправлен')
                    ->queries(
                        true: fn (Builder $query) => $query->where('note', 'like', '%[Доступ отправлен%'),
                        false: fn (Builder $query) => $query->where(function (Builder $q) {
                            $q->whereNull('note')
                                ->orWhere('note', 'not like', '%[Доступ отправлен%');
                        }),
                        blank: fn (Builder $query) => $query,
                    ),
                // --- Согласие на email-анонсы (галочка с чекаута) ---
                Tables\Filters\TernaryFilter::make('wants_email_announcements')
                    ->label('Согласие на email-анонсы')
                    ->placeholder('Все студенты')
                    ->trueLabel('Согласились на анонсы')
                    ->falseLabel('Отказались от анонсов'),

                // --- Состоит в группе (пул для разнесения по группам курса) ---
                Tables\Filters\SelectFilter::make('group')
                    ->label('Состоит в группе')
                    ->relationship('groups', 'name')
                    ->searchable()
                    ->preload(),

                // --- НОВЫЙ ФИЛЬТР ПО СТАТУСУ ---
                Tables\Filters\SelectFilter::make('global_status')
                    ->label('Статус студента')
                    ->options([
                        'Обычный студент' => 'Обычный студент',
                        'Техподдержка' => 'Техподдержка',
                        'VIP' => 'VIP',
                        'Занимается бесплатно' => 'Занимается бесплатно',
                        'Бартер' => 'Бартер',
                    ]),

                Tables\Filters\Filter::make('has_telegram')
                    ->label('Есть Telegram-бот')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('telegram_id')),

                Tables\Filters\Filter::make('has_vk')
                    ->label('Есть ВК-бот')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('vk_id')),

                // --- ФИЛЬТРЫ ПО АКТИВНОСТИ ---

                Tables\Filters\Filter::make('online_now')
                    ->label('Онлайн сейчас')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('last_activity_at', '>=', now()->subMinutes(5))
                    )
                    ->indicator('Онлайн сейчас'),

                Tables\Filters\Filter::make('active_today')
                    ->label('Активные сегодня')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('last_activity_at', today())
                    )
                    ->indicator('Активные сегодня'),

                Tables\Filters\Filter::make('inactive_7_days')
                    ->label('Неактивные 7+ дней')
                    ->query(fn (Builder $query): Builder => $query
                        ->where(function (Builder $q) {
                            $q->whereNull('last_activity_at')
                                ->orWhere('last_activity_at', '<', now()->subDays(7));
                        })
                        ->whereNotNull('last_login_at') // только те, кто вообще заходил
                    )
                    ->indicator('Неактивные 7+ дней'),

                Tables\Filters\Filter::make('inactive_30_days')
                    ->label('Неактивные 30+ дней')
                    ->query(fn (Builder $query): Builder => $query
                        ->where(function (Builder $q) {
                            $q->whereNull('last_activity_at')
                                ->orWhere('last_activity_at', '<', now()->subDays(30));
                        })
                        ->whereNotNull('last_login_at')
                    )
                    ->indicator('Неактивные 30+ дней'),

                Tables\Filters\Filter::make('never_logged_in')
                    ->label('Никогда не заходили')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNull('last_login_at')
                    )
                    ->indicator('Никогда не заходили'),

            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip('Открыть карточку'),

                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Редактировать'),

                Tables\Actions\Action::make('grant_prana')
                    ->iconButton()
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->tooltip('Начислить / списать прану')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->modalHeading(fn (User $record) => 'Прана студента: '.$record->name)
                    ->modalDescription(fn (User $record) => 'Текущий баланс: '.number_format((int) $record->prana_balance, 0, '.', ' ').' праны.')
                    ->modalSubmitActionLabel('Применить')
                    ->modalWidth('md')
                    ->form([
                        Forms\Components\ToggleButtons::make('direction')
                            ->label('Операция')
                            ->options([
                                'grant' => 'Начислить',
                                'deduct' => 'Списать',
                            ])
                            ->icons([
                                'grant' => 'heroicon-m-plus-circle',
                                'deduct' => 'heroicon-m-minus-circle',
                            ])
                            ->colors([
                                'grant' => 'success',
                                'deduct' => 'danger',
                            ])
                            ->default('grant')
                            ->inline()
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Количество')
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->required()
                            ->suffix('праны'),

                        Forms\Components\Textarea::make('comment')
                            ->label('Комментарий')
                            ->placeholder('За что? Например: бонус за участие в стриме.')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (User $record, array $data) {
                        $admin = auth()->user();
                        if (! $admin || ! \App\Support\RoleGate::adminOnly()) {
                            Notification::make()->title('Недостаточно прав.')->danger()->send();

                            return;
                        }

                        $amount = (int) $data['amount'];
                        $delta = $data['direction'] === 'deduct' ? -$amount : $amount;

                        try {
                            $newBalance = app(\App\Services\Prana\PranaService::class)
                                ->adminAdjust($record, $delta, $admin, $data['comment'] ?? null);

                            Notification::make()
                                ->title($delta > 0 ? 'Прана начислена' : 'Прана списана')
                                ->body(($delta > 0 ? '+' : '').number_format($delta, 0, '.', ' ')
                                    .' праны. Новый баланс: '
                                    .number_format($newBalance, 0, '.', ' ').'.')
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Не удалось списать')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Admin prana adjust failed', [
                                'user_id' => $record->id,
                                'admin_id' => $admin->id,
                                'delta' => $delta,
                                'error' => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Ошибка')
                                ->body('Что-то пошло не так. Подробности в логах.')
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('send_password')
                    ->iconButton()
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->tooltip('Сбросить и выслать пароль')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->requiresConfirmation()
                    ->modalHeading('Выслать новый пароль?')
                    ->modalDescription('Текущий пароль студента будет сброшен. Новый случайный пароль будет немедленно отправлен ему на почту.')
                    ->modalSubmitActionLabel('Да, выслать')
                    ->action(function (User $record) {
                        $email = trim((string) $record->email);

                        // Ранняя проверка, чтобы не сбросить пароль на "битом" юзере
                        if (
                            $email === ''
                            || str_ends_with($email, '@no-email.com')
                            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                        ) {
                            Notification::make()
                                ->title('Некорректный email')
                                ->body("У студента указан невалидный адрес: «{$email}». Сначала обновите email в карточке.")
                                ->danger()
                                ->send();

                            return;
                        }

                        $newPassword = Str::random(8);
                        $emailText = "Намасте, {$record->name}!\n\n"
                            ."Ваш пароль для доступа к личному кабинету Академии был сброшен администратором.\n\n"
                            ."Ваш новый пароль: {$newPassword}\n\n"
                            ."С уважением,\nОбщество ревнителей санскрита.";

                        try {
                            // Сначала — письмо, потом — обновление пароля
                            Mail::raw($emailText, function ($message) use ($email) {
                                $message->to($email)
                                    ->subject('Ваш новый пароль от личного кабинета');
                            });

                            $record->update(['password' => Hash::make($newPassword)]);

                            Notification::make()
                                ->title('Новый пароль успешно отправлен на почту студента!')
                                ->success()
                                ->send();
                        } catch (\Symfony\Component\Mime\Exception\RfcComplianceException $e) {
                            Notification::make()
                                ->title('Некорректный email')
                                ->body("Symfony Mailer отклонил адрес «{$email}». Пароль не сброшен.")
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Send password failed', [
                                'user_id' => $record->id,
                                'email' => $email,
                                'error' => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Ошибка отправки письма')
                                ->body('Пароль не сброшен. Подробности в логах: '.$e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    // --- ПРОСТАНОВКА БЛОКОВ ВХОДА/ВЫХОДА ИЗ ПРИМЕЧАНИЙ ---
                    // По выделенным студентам разбираем примечания во всех их курсах
                    // и проставляем распознанные блоки в пустые колонки. Модалка —
                    // сравнительная таблица (было → распознано → станет). То же, что
                    // в карточке студента, но сразу по многим студентам из списка.
                    Tables\Actions\BulkAction::make('blocksFromNoteBulk')
                        ->label('Блоки из примечаний')
                        ->icon('heroicon-m-sparkles')
                        ->color('warning')
                        ->visible(fn () => RoleGate::adminOnly())
                        ->modalHeading('Распознанные блоки из примечаний')
                        ->modalWidth('6xl')
                        ->modalSubmitActionLabel('Проставить пустые')
                        ->deselectRecordsAfterCompletion()
                        ->modalContent(fn (\Illuminate\Database\Eloquent\Collection $records) => view(
                            'filament.user-courses.blocks-preview-students',
                            ['rows' => self::buildBlocksPreviewForUsers($records)],
                        ))
                        ->action(fn (\Illuminate\Database\Eloquent\Collection $records) => self::applyBlocksFromNotesForUsers($records)),

                    // --- ПЕРЕНОС В ГРУППУ КУРСА (сплит курса на 2 группы) ---
                    // Отвязывает выбранных от остальных групп ЭТОГО курса и привязывает
                    // к целевой. Оплаты не трогаются (дублей Payment не возникает).
                    Tables\Actions\BulkAction::make('move_to_group')
                        ->label('Перенести в группу')
                        ->icon('heroicon-o-user-group')
                        ->color('warning')
                        ->visible(fn () => RoleGate::adminOnly())
                        ->requiresConfirmation()
                        ->modalHeading('Перенести выбранных в группу курса')
                        ->modalDescription('Студентов отвяжут от остальных групп ЭТОГО курса и привяжут к выбранной. Группы других курсов и оплаты не затрагиваются.')
                        ->modalSubmitActionLabel('Перенести')
                        ->form([
                            Forms\Components\Select::make('target_group_id')
                                ->label('Целевая группа')
                                ->options(\App\Models\Group::query()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->helperText('Курс определяется по выбранной группе.'),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $group = \App\Models\Group::with('courses')->find($data['target_group_id']);

                            if (! $group) {
                                Notification::make()->title('Группа не найдена')->danger()->send();

                                return;
                            }

                            $course = $group->courses->first();

                            if (! $course) {
                                Notification::make()
                                    ->title('У группы нет курса')
                                    ->body('Группа «'.$group->name.'» не привязана ни к одному курсу — перенос внутри курса невозможен.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // Группы этого курса, кроме целевой — из них отвязываем.
                            $siblingGroupIds = $course->groups()
                                ->where('groups.id', '!=', $group->id)
                                ->pluck('groups.id')
                                ->all();

                            $moved = 0;
                            foreach ($records as $user) {
                                if (! empty($siblingGroupIds)) {
                                    $user->groups()->detach($siblingGroupIds);
                                }
                                $user->groups()->syncWithoutDetaching([$group->id]);
                                $moved++;
                            }

                            Notification::make()
                                ->title('Перенос завершён')
                                ->body("Перенесено: {$moved} → группа «{$group->name}» (курс «{$course->title}»).")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // --- НОВАЯ КНОПКА: МАССОВАЯ РАССЫЛКА ДОСТУПОВ ---
                    Tables\Actions\BulkAction::make('send_bulk_access')
                        ->label('Разослать доступы')
                        ->icon('heroicon-o-envelope')
                        ->color('success')
                        ->visible(fn () => RoleGate::adminOnly())
                        ->requiresConfirmation()
                        ->modalHeading('Разослать доступы выбранным студентам?')
                        ->modalDescription('Система сгенерирует уникальные пароли и отправит письма. Студенты, которым доступ уже отправлялся (есть отметка в примечании), будут пропущены для защиты от спама.')
                        ->modalSubmitActionLabel('Да, отправить')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $sentCount = 0;
                            $skippedCount = 0;
                            $invalidEmails = []; // битые адреса
                            $failedEmails = []; // валидные с виду, но mailer упал

                            foreach ($records as $record) {
                                // 1. Защита от спама
                                if (str_contains($record->note ?? '', '[Доступ отправлен')) {
                                    $skippedCount++;

                                    continue;
                                }

                                // 2. Отсекаем заглушки и заведомо невалидные адреса
                                $email = trim((string) $record->email);

                                if (
                                    $email === ''
                                    || str_ends_with($email, '@no-email.com')
                                    || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                                ) {
                                    $invalidEmails[] = "#{$record->id} {$record->name} ({$email})";

                                    continue;
                                }

                                // 3. Генерируем пароль заранее, но НЕ сохраняем до успешной отправки
                                $newPassword = \Illuminate\Support\Str::random(8);

                                $emailText = "Намасте, {$record->name}!\n\n"
                                    ."Ваш доступ к личному кабинету обучающей платформы открыт.\n\n"
                                    .'Ссылка для входа: '.url('/login')."\n"
                                    ."Ваш логин (email): {$email}\n"
                                    ."Ваш пароль: {$newPassword}\n\n"
                                    ."С уважением,\nКоманда Общества ревнителей санскрита.";

                                try {
                                    // 4. Сначала пытаемся отправить письмо
                                    \Illuminate\Support\Facades\Mail::raw($emailText, function ($message) use ($email) {
                                        $message->to($email)
                                            ->subject('Ваш доступ к обучающей платформе');
                                    });

                                    // 5. И только если почта ушла — сохраняем пароль и ставим штамп
                                    $record->update([
                                        'password' => \Illuminate\Support\Facades\Hash::make($newPassword),
                                        'note' => trim(($record->note ?? '')."\n\n[Доступ отправлен: ".now()->format('d.m.Y H:i').']'),
                                    ]);

                                    $sentCount++;
                                } catch (\Symfony\Component\Mime\Exception\RfcComplianceException $e) {
                                    // RFC-невалидный адрес, который filter_var всё-таки пропустил
                                    $invalidEmails[] = "#{$record->id} {$record->name} ({$email})";
                                } catch (\Throwable $e) {
                                    // SMTP упал, таймаут, что угодно — логируем и идём дальше
                                    \Illuminate\Support\Facades\Log::error('Bulk access mail failed', [
                                        'user_id' => $record->id,
                                        'email' => $email,
                                        'error' => $e->getMessage(),
                                    ]);
                                    $failedEmails[] = "#{$record->id} {$record->name} ({$email})";
                                }
                            }

                            // 6. Собираем отчёт
                            $body = "Успешно отправлено: {$sentCount} шт.\n"
                                  ."Пропущено (уже отправлялось): {$skippedCount} шт.\n"
                                  .'Некорректный email: '.count($invalidEmails)." шт.\n"
                                  .'Ошибка отправки: '.count($failedEmails).' шт.';

                            if (! empty($invalidEmails)) {
                                $body .= "\n\nНевалидные:\n".implode("\n", array_slice($invalidEmails, 0, 10));
                                if (count($invalidEmails) > 10) {
                                    $body .= "\n… и ещё ".(count($invalidEmails) - 10);
                                }
                            }

                            \Filament\Notifications\Notification::make()
                                ->title('Рассылка завершена')
                                ->body($body)
                                ->{ (count($invalidEmails) + count($failedEmails)) > 0 ? 'warning' : 'success' }()
                                ->persistent() // чтобы куратор точно прочитал отчёт
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Строки сравнительной таблицы для массовой простановки блоков по студентам:
     * по каждому выделенному студенту — все его курсы с текущими/распознанными
     * блоками и тем, что реально проставится (только в пустые колонки).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>  $users
     * @return list<array<string, mixed>>
     */
    public static function buildBlocksPreviewForUsers(\Illuminate\Database\Eloquent\Collection $users): array
    {
        $users->loadMissing('courses');
        $rows = [];

        foreach ($users as $user) {
            foreach ($user->courses as $course) {
                $parsed = CourseNoteBlockParser::parse($course->pivot?->note);
                $currentEntry = $course->pivot?->joined_at_block;
                $currentExit = $course->pivot?->left_after_block;

                // Курсы без примечания и без распознанного — в таблицу не тащим.
                if (blank($course->pivot?->note)) {
                    continue;
                }

                $rows[] = [
                    'student' => $user->name,
                    'title' => $course->title,
                    'note' => $course->pivot?->note,
                    'current_entry' => $currentEntry,
                    'current_exit' => $currentExit,
                    'parsed_entry' => $parsed['entry'],
                    'parsed_exit' => $parsed['exit'],
                    'will_set_entry' => blank($currentEntry) && filled($parsed['entry']),
                    'will_set_exit' => blank($currentExit) && filled($parsed['exit']),
                ];
            }
        }

        return $rows;
    }

    /**
     * Применяет распознанные блоки по всем курсам выделенных студентов — только в
     * пустые колонки. Шлёт сводку-нотификацию.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>  $users
     */
    public static function applyBlocksFromNotesForUsers(\Illuminate\Database\Eloquent\Collection $users): void
    {
        $users->loadMissing('courses');
        $setEntry = 0;
        $setExit = 0;
        $touchedStudents = [];

        foreach ($users as $user) {
            foreach ($user->courses as $course) {
                $parsed = CourseNoteBlockParser::parse($course->pivot?->note);
                $payload = [];

                if (blank($course->pivot?->joined_at_block) && filled($parsed['entry'])) {
                    $payload['joined_at_block'] = $parsed['entry'];
                }
                if (blank($course->pivot?->left_after_block) && filled($parsed['exit'])) {
                    $payload['left_after_block'] = $parsed['exit'];
                }

                if (empty($payload)) {
                    continue;
                }

                $user->courses()->updateExistingPivot($course->id, $payload);
                $setEntry += isset($payload['joined_at_block']) ? 1 : 0;
                $setExit += isset($payload['left_after_block']) ? 1 : 0;
                $touchedStudents[$user->id] = true;
            }
        }

        if ($setEntry === 0 && $setExit === 0) {
            Notification::make()
                ->warning()
                ->title('Нечего проставлять')
                ->body('По выделенным студентам блоки не распознаны или уже заполнены.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Блоки проставлены')
            ->body('Студентов затронуто: '.count($touchedStudents).". Вход: {$setEntry}, выход: {$setExit}.")
            ->send();
    }

    public static function getRelations(): array
    {
        return [
            // ВОТ ЗДЕСЬ ИСПРАВЛЕНИЕ: добавили UserResource\
            UserResource\RelationManagers\CoursesRelationManager::class,
            UserResource\RelationManagers\PaymentsRelationManager::class,
            UserResource\RelationManagers\PaymentPromisesRelationManager::class,
            UserResource\RelationManagers\LessonAccessGrantsRelationManager::class,
            UserResource\RelationManagers\IndividualDiscountsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            // --- ДОБАВЛЯЕМ МАРШРУТ ДЛЯ СОЗДАННОЙ СТРАНИЦЫ ---
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
