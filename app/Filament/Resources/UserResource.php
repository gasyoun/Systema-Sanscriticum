<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Jobs\SendMessengerAlerts;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\ScheduledReminder;
use App\Models\User;
use App\Services\Access\LoginLinkNotifier;
use App\Services\Access\StudentUnblockService;
use App\Services\Prana\PranaService;
use App\Services\Schedule\CanvasMoney;
use App\Services\Schedule\TextbookScale;
use App\Services\StuckStudentsReport;
use App\Support\CourseNoteBlockParser;
use App\Support\Impersonation;
use App\Support\PhoneCountrySuggest;
use App\Support\RoleGate;
use App\Support\Roles;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Mime\Exception\RfcComplianceException;

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
        // Бухгалтер — сверка (read-only); куратор (manager) — поиск + magic link;
        // создание/правка/удаление — adminOnly()/canEdit().
        return RoleGate::any(Roles::ADMIN, Roles::ACCOUNTANT, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canView($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::ACCOUNTANT, Roles::MANAGER);
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
                            ->maxLength(255)
                            ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                // H3909 (MG 02-09-2026): страна предлагается из
                                // кода телефона, но только если поле пусто —
                                // вписанное руками не перетирается.
                                if (blank($state) || filled($get('country'))) {
                                    return;
                                }

                                $country = PhoneCountrySuggest::fromPhone($state);
                                if ($country !== null) {
                                    $set('country', $country);
                                }
                            }),

                        // H3909 — спрашиваем у каждого ученика (MG 02-09-2026):
                        // по стране куратор понимает, что платить придётся
                        // через PayPal, и переименовывает карточку по правилу
                        // «Имя, Город, Страна».
                        Forms\Components\TextInput::make('city')
                            ->label('Город')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('country')
                            ->label('Страна')
                            ->maxLength(255),

                        // H4434 (MG 09-09-2026): таймзона ученика. Источник виден
                        // рядом (manual/device/admin) — чтобы куратор понимал,
                        // чьё это решение. Пусто = МСК-дефолт (никаких догадок по IP:
                        // большинство РФ-учеников ходит через NL/DE VPN).
                        Forms\Components\Select::make('timezone')
                            ->label('Часовой пояс (постоянный)')
                            ->options(self::timezoneOptions())
                            ->searchable()
                            ->nullable()
                            ->helperText('Пусто = Москва (МСК). Источник: '.($record?->tz_source ?? 'не задан')),

                        Forms\Components\Select::make('tz_override')
                            ->label('Временное пребывание')
                            ->options(self::timezoneOptions())
                            ->searchable()
                            ->nullable()
                            ->helperText('Напр. Индия на 1.5 мес. Дата возврата ниже; после неё ученик вернётся к постоянному поясу.'),

                        Forms\Components\DatePicker::make('tz_override_until')
                            ->label('Временное пребывание до (включительно)'),

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
                            ->preload()
                            // ВНИМАНИЕ: снятие галочки здесь делает жёсткий detach
                            // (sync удаляет строку group_user) → студент ТЕРЯЕТ доступ
                            // к курсу в ЛК. Это инструмент выдачи/ОТЗЫВА доступа.
                            // Для «больше не учится, но доступ сохранить» используйте
                            // раздел «Группы (активный состав)» ниже (мягкий выход).
                            ->helperText('⚠️ Снятие галочки ОТЗЫВАЕТ доступ к курсу (студент перестанет видеть его в кабинете). Если студент просто закончил/ушёл, но доступ к оплаченному нужно сохранить — не трогайте это поле, а используйте раздел «Группы (активный состав)» ниже.'),

                        Forms\Components\Select::make('role')
                            ->label('Роль в админке')
                            ->helperText('Без роли — обычный студент, без доступа в админку. Роль «Преподаватель» назначается через раздел «Преподаватели».')
                            ->options(function (?Model $record) {
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
                            ->rule(function (?Model $record) {
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

                                return Rule::in([null, ...$keys]);
                            })
                            ->placeholder('— Студент —')
                            ->live()
                            ->visible(fn () => RoleGate::adminOnly()),

                        Toggle::make('is_lecture_editor')
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
                            ->content(fn (?User $record) => $record
                                ? number_format((int) $record->prana_balance, 0, '.', ' ').' праны'
                                : '—'),
                    ])
                    ->visible(fn (string $operation) => $operation !== 'create' && RoleGate::adminOnly())
                    ->columns(1),
            ]);
    }

    /**
     * H4434 — общий список зон для селекторов (постоянная + оверрайд).
     * Практичный набор: РФ + типовые нон-МСК страны учеников; searchable
     * покрывает остальное — валидация IANA на записи в TimezoneController.
     */
    protected static function timezoneOptions(): array
    {
        return [
            'Europe/Moscow' => 'Москва (МСК)',
            'Europe/Kaliningrad' => 'Калининград',
            'Asia/Yekaterinburg' => 'Екатеринбург',
            'Asia/Novosibirsk' => 'Новосибирск',
            'Asia/Vladivostok' => 'Владивосток',
            'Europe/Madrid' => 'Мадрид / Испания',
            'Europe/Berlin' => 'Берлин / Германия',
            'Europe/Amsterdam' => 'Амстердам / Нидерланды',
            'Europe/Paris' => 'Париж / Франция',
            'Europe/Rome' => 'Рим / Италия',
            'Europe/Riga' => 'Рига / Латвия',
            'Europe/Vilnius' => 'Вильнюс / Литва',
            'Europe/Tallinn' => 'Таллин / Эстония',
            'Europe/Kyiv' => 'Киев',
            'Europe/Sofia' => 'София',
            'America/Los_Angeles' => 'Лос-Анджелес',
            'America/New_York' => 'Нью-Йорк',
            'Asia/Tbilisi' => 'Тбилиси',
            'Asia/Yerevan' => 'Ереван',
            'Asia/Almaty' => 'Алматы',
            'Asia/Tashkent' => 'Ташкент',
            'Asia/Kolkata' => 'Дели / Индия',
            'Asia/Jerusalem' => 'Иерусалим',
            'Asia/Bangkok' => 'Бангкок',
        ];
    }

    /**
     * Рендерит примечание куратора с кликабельными ссылками.
     * XSS-безопасно: сначала экранируем весь текст, затем вставляем только наши <a>.
     */
    protected static function linkifyNote(?string $note): HtmlString
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

        return new HtmlString(nl2br($html));
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

                        TextEntry::make('city')
                            ->label('Город')
                            ->placeholder('— не спросили —'),

                        TextEntry::make('country')
                            ->label('Страна')
                            ->placeholder('— не спросили —'),

                        // H4434: эффективная зона + источник (MG 09-09-2026).
                        TextEntry::make('effectiveTimezone')
                            ->label('Часовой пояс (эффективный)')
                            ->state(fn (User $record): string => $record->effectiveTimezone() ?? 'Europe/Moscow (по умолчанию)')
                            ->badge()
                            ->color(fn (User $record): string => $record->isNonMskTimezone() ? 'warning' : 'gray'),

                        TextEntry::make('tz_source')
                            ->label('Источник пояса')
                            ->formatStateUsing(fn (?string $state) => match ($state) {
                                'manual' => 'указал сам',
                                'device' => 'часы устройства',
                                'admin' => 'куратор',
                                default => 'не задан (МСК)',
                            })
                            ->placeholder('не задан (МСК)'),

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
                                    ? ' (с '.Carbon::parse($d)->format('d.m.Y').')'
                                    : '';
                                $handle = $record->telegram_username ? ' @'.$record->telegram_username : '';
                                $tg = $record->telegram_id ? '✈ Telegram'.$handle.$fmt($record->telegram_connected_at) : '';
                                $vk = $record->vk_id ? '💬 VK'.$fmt($record->vk_connected_at) : '';
                                $parts = array_filter([$tg, $vk]);

                                return ! empty($parts) ? implode(' · ', $parts) : 'Нет мессенджеров';
                            }),

                        TextEntry::make('telegram_username')
                            ->label('Telegram @username')
                            // Кликабельная ссылка на профиль; @username ловится ботом
                            // при привязке/сообщении и может отсутствовать (его нет
                            // у части пользователей Telegram).
                            ->state(fn (User $record): string => $record->telegram_username
                                ? '@'.$record->telegram_username
                                : '—')
                            ->url(fn (User $record): ?string => $record->telegramLink())
                            ->openUrlInNewTab()
                            ->visible(fn (User $record): bool => (bool) $record->telegram_id),
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
                                : Carbon::parse($state)->diffForHumans())
                            ->tooltip(fn ($state): ?string => $state
                                ? Carbon::parse($state)->translatedFormat('d.m.Y H:i:s')
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

                // Прогресс по урокам (% пройдено) и сводка ДЗ — куратору, чтобы видеть,
                // где студент застрял, не выходя из карточки.
                InfoSection::make('Прогресс обучения')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->schema([
                        ViewEntry::make('learning_progress')
                            ->hiddenLabel()
                            ->view('filament.user.learning-progress')
                            ->columnSpanFull(),
                    ]),

                // H4435 (MG 08-09): посещаемость + позиция на шкале учебника.
                // Наши занятия и уроки учебника — разные шкалы, подписи раздельные.
                InfoSection::make('Посещаемость и канва')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->schema([
                        ViewEntry::make('attendance_canvas')
                            ->hiddenLabel()
                            ->view('filament.user.attendance-canvas')
                            ->columnSpanFull(),
                    ]),

                // Скор платёжной дисциплины (см. docs/discipline-score-spec.md) — advisory-only,
                // рядом с вкладкой «Обещания оплатить». Не влияет на скидки/рассрочку/доступ.
                InfoSection::make('Дисциплина')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->schema([
                        ViewEntry::make('discipline_score')
                            ->hiddenLabel()
                            ->view('filament.user.discipline-score')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Прогресс студента для карточки куратора: процент пройденных уроков по каждому
     * курсу (та же формула, что в кабинете — completedLessons / уроки группы) и
     * сводка домашних работ по статусам.
     *
     * @return array{courses: list<array{title: string, completed: int, total: int, percent: int}>, homework: array{submitted: int, needs_revision: int, accepted: int}}
     */
    public static function learningProgress(User $record): array
    {
        $record->loadMissing('courses');

        $courses = $record->courses->map(function (Course $course) use ($record): array {
            $total = $course->lessons()->forUserGroups($record)->count();
            $completed = $record->completedLessons()
                ->where('lessons.course_id', $course->id)
                ->count();

            return [
                'title' => (string) $course->title,
                'completed' => $completed,
                'total' => $total,
                'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            ];
        })->values()->all();

        $counts = $record->homeworkSubmissions()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'courses' => $courses,
            'homework' => [
                'submitted' => (int) ($counts[HomeworkSubmission::STATUS_SUBMITTED] ?? 0),
                'needs_revision' => (int) ($counts[HomeworkSubmission::STATUS_NEEDS_REVISION] ?? 0),
                'accepted' => (int) ($counts[HomeworkSubmission::STATUS_ACCEPTED] ?? 0),
            ],
        ];
    }

    /**
     * H4435 (MG 08-09): посещаемость + позиция на канве по курсам студента.
     * Две шкалы раздельно: «наши занятия» (посещения) и «урок учебника»
     * (курсор из TextbookScale). Эмпирический вес урока — без линейных пропорций.
     *
     * @return array{rows: list<array{course_title: string, family: string, total: int, group_cursor: int, student_cursor: int, lag: int, last_canvas: ?string, sessions: list<array{date: string, title: string, canvas: ?string, kind: string}>}>}
     */
    public static function attendanceCanvas(User $record): array
    {
        $record->loadMissing('groups.courses');
        $rows = [];

        foreach ($record->groups as $group) {
            foreach ($group->courses as $course) {
                $family = TextbookScale::courseFamilyPublic((string) $course->title);
                if ($family === null) {
                    continue;
                }
                $total = TextbookScale::families()[$family]['total'];

                $lessons = Lesson::where('course_id', $course->id)
                    ->whereNotNull('lesson_date')->orderBy('lesson_date')->get();
                $groupCursor = TextbookScale::cursor($lessons, $family);

                // Позиция студента: макс. предмет канвы на записях уроков, дата
                // которых <= последнего ФАКТА студента (WebinarAttendance).
                $lastFact = $record->attendances()
                    ->whereIn('schedule_id', App\Models\Schedule::where('group_id', $group->id)->pluck('id'))
                    ->latest('created_at')->first();
                $studentCursor = 0;
                $lastCanvas = null;
                if ($lastFact) {
                    $factDate = $lastFact->created_at->copy()->startOfDay();
                    $studentLessons = $lessons->filter(
                        fn ($l) => $l->lesson_date !== null && $l->lesson_date->startOfDay()->lte($factDate),
                    );
                    $studentCursor = TextbookScale::cursor($studentLessons, $family);
                    $own = $studentLessons->firstWhere('lesson_date', $factDate)
                        ?? $studentLessons->last();
                    if ($own) {
                        $items = TextbookScale::parseTitle((string) $own->title);
                        $chitki = array_filter($items, fn ($i) => $i['family'] === $family && $i['kind'] === 'chitka');
                        if ($chitki !== []) {
                            $top = max(array_column($chitki, 'lesson'));
                            $lastCanvas = TextbookScale::label($family, $top, 'chitka');
                        }
                    }
                }

                // Последние 10 занятий группы: дата, заголовок записи, предмет.
                $sessions = [];
                foreach ($lessons->sortByDesc('lesson_date')->take(10) as $l) {
                    $items = TextbookScale::parseTitle((string) $l->title);
                    $chitki = array_filter($items, fn ($i) => $i['family'] === $family && $i['kind'] === 'chitka');
                    $canvas = $chitki !== [] ? TextbookScale::label($family, max(array_column($chitki, 'lesson')), 'chitka') : null;
                    $sessions[] = [
                        'date' => $l->lesson_date?->format('d.m.Y') ?? '—',
                        'title' => (string) $l->title,
                        'canvas' => $canvas,
                        'kind' => 'lesson',
                    ];
                }

                // H4443: персональные деньги — неоплаченные блоки от курсора группы.
                $groupBlock = 0;
                foreach ($lessons as $l) {
                    $chitki = array_filter(
                        TextbookScale::parseTitle((string) $l->title),
                        fn (array $i): bool => $i['family'] === $family && $i['kind'] === 'chitka',
                    );
                    if ($chitki !== [] && max(array_column($chitki, 'lesson')) === $groupCursor) {
                        $groupBlock = TextbookScale::parseBlockMarker((string) $l->title)
                            ?? (int) ceil($groupCursor / TextbookScale::lessonsPerBlock());
                        break;
                    }
                }
                $blocksTotal = TextbookScale::blocksTotal($course->id, $total);
                $unpaid = CanvasMoney::unpaidFor($record, $course, $groupBlock, $blocksTotal);

                $rows[] = [
                    'course_title' => (string) $course->title,
                    'family' => $family,
                    'total' => $total,
                    'group_cursor' => $groupCursor,
                    'student_cursor' => $studentCursor,
                    'lag' => $groupCursor - $studentCursor,
                    'last_canvas' => $lastCanvas,
                    'unpaid' => CanvasMoney::humanize($unpaid),
                    'sessions' => $sessions,
                ];
            }
        }

        return ['rows' => $rows];
    }

    /**
     * SVG-«аватарка» с инициалом для тех, у кого нет фото из TG/VK.
     * Возвращает data-URI (без внешних запросов), цвета — как у чат-кружка.
     */
    protected static function avatarInitialPlaceholder(?string $name): string
    {
        $initial = htmlspecialchars(mb_strtoupper(mb_substr($name ?: 'С', 0, 1)), ENT_QUOTES);
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='40' height='40'>"
            ."<rect width='40' height='40' fill='#ffedd5'/>"
            ."<text x='20' y='21' font-family='sans-serif' font-size='18' font-weight='bold' "
            ."fill='#ea580c' text-anchor='middle' dominant-baseline='central'>{$initial}</text>"
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // --- АВАТАРКА ---
                // Реальное фото из TG/VK (avatar_path в public-диске). Если фото нет —
                // генерим SVG-кружок с инициалом в тех же цветах, что и в чате
                // (data-URI, без внешних запросов). circular() обрезает по кругу.
                Tables\Columns\ImageColumn::make('avatar_path')
                    ->label('')
                    ->circular()
                    ->disk('public')
                    ->size(40)
                    ->defaultImageUrl(fn ($record): string => static::avatarInitialPlaceholder($record->name))
                    ->extraImgAttributes(['loading' => 'lazy']),

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
                        $tg = $record->telegram_id
                            ? '✈ TG'.($record->telegram_username ? ' @'.$record->telegram_username : '')
                            : '';
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

                // --- @USERNAME TG (скрыт по умолчанию; даёт поиск по @username) ---
                Tables\Columns\TextColumn::make('telegram_username')
                    ->label('TG @username')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => $state ? '@'.$state : '—')
                    ->url(fn (User $record): ?string => $record->telegramLink())
                    ->openUrlInNewTab(),

                // --- ПОДКЛЮЧЕНИЕ БОТА (скрыто по умолчанию; для сортировки/аналитики) ---
                Tables\Columns\TextColumn::make('telegram_connected_at')
                    ->label('Подключил TG')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): ?string => $state
                        ? Carbon::parse($state)->format('d.m.Y H:i')
                        : null),

                Tables\Columns\TextColumn::make('vk_connected_at')
                    ->label('Подключил VK')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): ?string => $state
                        ? Carbon::parse($state)->format('d.m.Y H:i')
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

                        return Carbon::parse($state)->diffForHumans();
                    })
                    ->tooltip(fn ($state): ?string => $state
                        ? Carbon::parse($state)->translatedFormat('d.m.Y H:i:s')
                        : null)
                    ->color(function ($state): string {
                        if ($state === null) {
                            return 'gray';
                        }
                        $d = Carbon::parse($state);
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
                        $d = Carbon::parse($state);
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

                Tables\Columns\IconColumn::make('wants_messenger_announcements')
                    ->label('Анонсы в мессенджеры')
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

                // --- Согласие на анонсы в мессенджеры (152-ФЗ гейт TG/VK-рассылки) ---
                Tables\Filters\TernaryFilter::make('wants_messenger_announcements')
                    ->label('Согласие на анонсы в мессенджеры')
                    ->placeholder('Все студенты')
                    ->trueLabel('Согласились на анонсы')
                    ->falseLabel('Отписались от анонсов'),

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

                // Сегмент «застрявшие» — та же логика, что в дашборд-виджете
                // (неактивен 14+ дн / ДЗ на доработке без пересдачи). Удобно как
                // адресат для рассылки «вернись к учёбе».
                Tables\Filters\Filter::make('stuck')
                    ->label('Застрявшие (неактив./ДЗ висит)')
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'id', StuckStudentsReport::query()->select('users.id')
                    ))
                    ->indicator('Застрявшие'),

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

                // --- РЕЖИМ ПРОСМОТРА ЗА ПОЛЬЗОВАТЕЛЕМ (H1947) ---
                // Только супер-админ и только при включённом флаге; видимость —
                // Impersonation::canImpersonate(), она же перепроверяется на
                // сервере в контроллере (кнопка ничего не авторизует).
                Tables\Actions\Action::make('impersonate_student')
                    ->iconButton()
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->tooltip('Открыть кабинет как студент')
                    ->visible(fn (User $record) => Impersonation::canImpersonate($record, Impersonation::MODE_STUDENT))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => 'Открыть кабинет как «'.$record->name.'»?')
                    ->modalDescription('Вы увидите кабинет глазами этого студента. Денежные действия в режиме запрещены, старт и выход пишутся в журнал. Вернуться — кнопкой в верхней плашке.')
                    ->modalSubmitActionLabel('Открыть кабинет')
                    ->action(fn (User $record) => redirect()->to(
                        Impersonation::startUrl($record, Impersonation::MODE_STUDENT)
                    )),

                Tables\Actions\Action::make('impersonate_manager')
                    ->iconButton()
                    ->icon('heroicon-o-identification')
                    ->color('info')
                    ->tooltip('Войти как куратор')
                    ->visible(fn (User $record) => Impersonation::canImpersonate($record, Impersonation::MODE_MANAGER))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => 'Войти как куратор «'.$record->name.'»?')
                    ->modalDescription('Панель будет вести себя ровно как у куратора: права супер-админа на время режима не действуют. Вернуться — кнопкой в верхней плашке.')
                    ->modalSubmitActionLabel('Войти как куратор')
                    ->action(fn (User $record) => redirect()->to(
                        Impersonation::startUrl($record, Impersonation::MODE_MANAGER)
                    )),

                Tables\Actions\Action::make('impersonate_teacher')
                    ->iconButton()
                    ->icon('heroicon-o-academic-cap')
                    ->color('info')
                    ->tooltip('Войти как преподаватель')
                    ->visible(fn (User $record) => Impersonation::canImpersonate($record, Impersonation::MODE_TEACHER))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => 'Войти как преподаватель «'.$record->name.'»?')
                    ->modalDescription('Панель, «Мой хинди» и «Моя зарплата» будут как у этого преподавателя: права супер-админа на время режима не действуют. Вернуться — кнопкой в верхней плашке.')
                    ->modalSubmitActionLabel('Войти как преподаватель')
                    ->action(fn (User $record) => redirect()->to(
                        Impersonation::startUrl($record, Impersonation::MODE_TEACHER)
                    )),

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
                        if (! $admin || ! RoleGate::adminOnly()) {
                            Notification::make()->title('Недостаточно прав.')->danger()->send();

                            return;
                        }

                        $amount = (int) $data['amount'];
                        $delta = $data['direction'] === 'deduct' ? -$amount : $amount;

                        try {
                            $newBalance = app(PranaService::class)
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
                            Log::error('Admin prana adjust failed', [
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
                        } catch (RfcComplianceException $e) {
                            Notification::make()
                                ->title('Некорректный email')
                                ->body("Symfony Mailer отклонил адрес «{$email}». Пароль не сброшен.")
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            Log::error('Send password failed', [
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

                // --- РАЗБЛОКИРОВАТЬ СТУДЕНТА ОДНИМ КЛИКОМ (H849) ---
                // У приложения нет флага «бан»: мешает войти только IP-троттл
                // (сам спадает за минуту). Реально спасает застрявшего рабочая
                // ССЫЛКА ДЛЯ ВХОДА, которую куратор/админ передаёт студенту
                // (в т.ч. в Telegram), минуя сломанную почту. Кнопка снимает
                // троттл + создаёт одноразовую magic-ссылку (+ опц. сброс пароля).
                Tables\Actions\Action::make('unblock')
                    ->iconButton()
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->tooltip('Разблокировать (ссылка для входа)')
                    ->visible(fn () => RoleGate::canIssueStudentLoginLink())
                    ->form([
                        // Умолчание — ВЫКЛЮЧЕНО, см. тот же комментарий в
                        // AccessAttemptResource: отправка студенту включается
                        // осознанно, иначе куратор продублирует сообщение.
                        Toggle::make('send_email')
                            ->label('Отправить ссылку на почту')
                            ->helperText('Не включайте, если у студента как раз сломана почта.')
                            ->default(false),
                        Toggle::make('send_messengers')
                            ->label('Отправить ссылку в мессенджеры (Telegram / VK / Max)')
                            ->helperText('Уйдёт в те каналы, которые привязаны к аккаунту.')
                            ->default(false),
                        Toggle::make('reset_password')
                            ->label('Также сбросить пароль')
                            ->helperText('Обычно не нужно: ссылка входит без пароля. Пароль студенту не отправляется — передайте лично.')
                            ->default(false),
                    ])
                    ->modalHeading('Разблокировать студента?')
                    ->modalDescription('Снимем троттл и создадим одноразовую ссылку для входа (24 ч). Ссылку покажем вам; включите галочки, если хотите, чтобы мы отправили её студенту сами.')
                    ->modalSubmitActionLabel('Разблокировать')
                    ->action(function (User $record, array $data) {
                        if (! LoginLinkNotifier::hasDeliverableEmail($record)) {
                            $email = trim((string) $record->email);
                            Notification::make()
                                ->title('Некорректный email')
                                ->body("У студента невалидный адрес: «{$email}». Ссылка всё равно создана, но письмо ему не уйдёт — передайте ссылку вручную или мессенджером.")
                                ->warning()
                                ->send();
                        }

                        $result = app(StudentUnblockService::class)
                            ->unblock($record, auth()->id(), (bool) ($data['reset_password'] ?? false));

                        $report = app(LoginLinkNotifier::class)->notify(
                            $record,
                            $result['login_link'],
                            (bool) ($data['send_email'] ?? false),
                            (bool) ($data['send_messengers'] ?? false),
                        );

                        $body = 'Доставка: '.LoginLinkNotifier::reportSummary($report);
                        $body .= "\n\nОдноразовая ссылка для входа (24 ч) — если не дошла, передайте сами:\n{$result['login_link']}";
                        if ($result['password'] !== null) {
                            $body .= "\n\nВременный пароль (студенту не отправлен): {$result['password']}";
                        }

                        Notification::make()
                            ->title('Готово — ссылка выдана')
                            ->body($body)
                            ->persistent()
                            ->success()
                            ->send();
                    }),

                // --- РАЗОВОЕ НАПОМИНАНИЕ НА ДАТУ ---
                // Куратор ставит текст + дату/время один раз — reminders:send-due
                // (каждые 15 минут) отправит его сам. Снимает риск того, что
                // человек забудет напомнить человеку вручную (например: студент
                // уехал и просит написать ему после конкретного числа).
                Tables\Actions\Action::make('schedule_reminder')
                    ->iconButton()
                    ->icon('heroicon-o-bell-alert')
                    ->color('info')
                    ->tooltip('Запланировать напоминание')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->modalHeading(fn (User $record) => 'Напоминание для: '.$record->name)
                    ->modalSubmitActionLabel('Запланировать')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Textarea::make('message')
                            ->label('Текст напоминания')
                            ->required()
                            ->rows(4)
                            ->maxLength(2000),

                        Forms\Components\DateTimePicker::make('scheduled_for')
                            ->label('Отправить не раньше')
                            ->native(false)
                            ->seconds(false)
                            ->minDate(now())
                            ->default(now()->addDay())
                            ->required(),

                        Forms\Components\CheckboxList::make('channels')
                            ->label('Каналы')
                            ->options([
                                'to_telegram' => 'Telegram',
                                'to_vk' => 'ВКонтакте',
                                'to_email' => 'Email',
                            ])
                            ->default(['to_telegram'])
                            ->required()
                            ->columns(3),
                    ])
                    ->action(function (User $record, array $data) {
                        $channels = $data['channels'] ?? [];

                        ScheduledReminder::create([
                            'user_id' => $record->id,
                            'created_by' => auth()->id(),
                            'message' => $data['message'],
                            'to_telegram' => in_array('to_telegram', $channels, true),
                            'to_vk' => in_array('to_vk', $channels, true),
                            'to_email' => in_array('to_email', $channels, true),
                            'scheduled_for' => $data['scheduled_for'],
                        ]);

                        Notification::make()
                            ->title('Напоминание запланировано')
                            ->success()
                            ->send();
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
                        ->modalContent(fn (Collection $records) => view(
                            'filament.user-courses.blocks-preview-students',
                            ['rows' => self::buildBlocksPreviewForUsers($records)],
                        ))
                        ->action(fn (Collection $records) => self::applyBlocksFromNotesForUsers($records)),

                    // --- H4434: ПОГОЛОВНОЕ «ПРИСВОИТЬ ЕВРОПЕ/МОСКВУ» (MG 09-09-2026) ---
                    // VPN-фактор: большинство РФ-учеников ходит через NL/DE VPN, их
                    // IP-гео отравлено. Куратор выделяет таких (фильтр по телефону
                    // +7/стране/созданию) и одним действием ставит Europe/Moscow,
                    // чтобы device-TZ захват и DST-алерты не ошиблись.
                    Tables\Actions\BulkAction::make('assignMskTimezone')
                        ->label('Присвоить МСК (VPN-Россия)')
                        ->icon('heroicon-o-clock')
                        ->color('gray')
                        ->visible(fn () => RoleGate::adminOnly())
                        ->requiresConfirmation()
                        ->modalHeading('Присвоить Europe/Moscow выделенным ученикам')
                        ->modalDescription('Ставит постоянный пояс Москва (МСК) и сбрасывает временное пребывание. Для РФ-учеников за VPN: их IP-гео показывает Нидерланды/Германию, но живут они по МСК.')
                        ->modalSubmitActionLabel('Присвоить МСК')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $count = 0;

                            foreach ($records as $user) {
                                $user->timezone = 'Europe/Moscow';
                                $user->tz_source = 'admin';
                                $user->tz_override = null;
                                $user->tz_override_until = null;
                                $user->save();
                                $count++;
                            }

                            Notification::make()
                                ->title('Пояс присвоен')
                                ->body("Europe/Moscow установлен для {$count} учеников (источник: admin).")
                                ->success()
                                ->send();
                        }),

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
                                ->options(Group::query()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->helperText('Курс определяется по выбранной группе.'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $group = Group::with('courses')->find($data['target_group_id']);

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

                    // --- СЕГМЕНТНАЯ РАССЫЛКА В МЕССЕНДЖЕРЫ ---
                    // Сегмент = выбранные студенты (отфильтрованные фильтрами выше:
                    // группа/курс/застрявшие/неактивные…). Превью охвата + отправка
                    // через очередь (SendMessengerAlerts сам форматирует TG/VK).
                    Tables\Actions\BulkAction::make('send_messenger')
                        ->label('Написать в TG/VK')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('info')
                        ->visible(fn () => RoleGate::adminOnly())
                        ->modalHeading('Сообщение выбранным студентам')
                        ->modalSubmitActionLabel('Отправить')
                        ->modalContent(fn (Collection $records) => view(
                            'filament.users.messenger-reach',
                            self::messengerReach($records),
                        ))
                        ->form([
                            Forms\Components\Textarea::make('message')
                                ->label('Текст сообщения')
                                ->required()
                                ->rows(5)
                                ->maxLength(4000)
                                ->helperText('Можно с переносами строк. HTML-ссылки <a href> превратятся в кликабельные/текстовые автоматически.'),
                            Toggle::make('to_telegram')->label('В Telegram')->default(true),
                            Toggle::make('to_vk')->label('В VK')->default(true),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $toTg = (bool) ($data['to_telegram'] ?? false);
                            $toVk = (bool) ($data['to_vk'] ?? false);

                            if (! $toTg && ! $toVk) {
                                Notification::make()->warning()->title('Не выбран ни один канал')->send();

                                return;
                            }

                            $sent = 0;
                            $skipped = 0;
                            foreach ($records as $user) {
                                $reachable = ($toTg && $user->telegram_id) || ($toVk && $user->vk_id);
                                if (! $reachable) {
                                    $skipped++;

                                    continue;
                                }
                                SendMessengerAlerts::dispatch($user, $data['message'], $toTg, $toVk);
                                $sent++;
                            }

                            Notification::make()
                                ->success()
                                ->title('Рассылка поставлена в очередь')
                                ->body("Отправляется: {$sent}. Пропущено (нет привязанного мессенджера): {$skipped}.")
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
                        ->action(function (Collection $records) {
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
                                $newPassword = Str::random(8);

                                $emailText = "Намасте, {$record->name}!\n\n"
                                    ."Ваш доступ к личному кабинету обучающей платформы открыт.\n\n"
                                    .'Ссылка для входа: '.url('/login')."\n"
                                    ."Ваш логин (email): {$email}\n"
                                    ."Ваш пароль: {$newPassword}\n\n"
                                    ."С уважением,\nКоманда Общества ревнителей санскрита.";

                                try {
                                    // 4. Сначала пытаемся отправить письмо
                                    Mail::raw($emailText, function ($message) use ($email) {
                                        $message->to($email)
                                            ->subject('Ваш доступ к обучающей платформе');
                                    });

                                    // 5. И только если почта ушла — сохраняем пароль и ставим штамп
                                    $record->update([
                                        'password' => Hash::make($newPassword),
                                        'note' => trim(($record->note ?? '')."\n\n[Доступ отправлен: ".now()->format('d.m.Y H:i').']'),
                                    ]);

                                    $sentCount++;
                                } catch (RfcComplianceException $e) {
                                    // RFC-невалидный адрес, который filter_var всё-таки пропустил
                                    $invalidEmails[] = "#{$record->id} {$record->name} ({$email})";
                                } catch (\Throwable $e) {
                                    // SMTP упал, таймаут, что угодно — логируем и идём дальше
                                    Log::error('Bulk access mail failed', [
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

                            Notification::make()
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
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    public static function buildBlocksPreviewForUsers(Collection $users): array
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
     * @param  Collection<int, User>  $users
     */
    public static function applyBlocksFromNotesForUsers(Collection $users): void
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

    /**
     * Охват рассылки по выбранным студентам — для превью в модалке.
     *
     * @param  Collection<int, User>  $records
     * @return array{total: int, tg: int, vk: int, reachable: int}
     */
    public static function messengerReach(Collection $records): array
    {
        return [
            'total' => $records->count(),
            'tg' => $records->filter(fn ($u) => filled($u->telegram_id))->count(),
            'vk' => $records->filter(fn ($u) => filled($u->vk_id))->count(),
            'reachable' => $records->filter(fn ($u) => filled($u->telegram_id) || filled($u->vk_id))->count(),
        ];
    }

    public static function getRelations(): array
    {
        return [
            // ВОТ ЗДЕСЬ ИСПРАВЛЕНИЕ: добавили UserResource\
            UserResource\RelationManagers\CoursesRelationManager::class,
            UserResource\RelationManagers\GroupsRelationManager::class,
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
