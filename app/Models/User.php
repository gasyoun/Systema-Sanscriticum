<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use App\Services\Messaging\SmsRuChannel;
use App\Services\Prana\PranaSettings;
use App\Support\Roles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// --- ДОБАВЛЯЕМ КЛАССЫ ДЛЯ ЗАЩИТЫ FILAMENT ---
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

// --- УКАЗЫВАЕМ, ЧТО ЮЗЕР ИСПОЛЬЗУЕТ ИНТЕРФЕЙС FILAMENT ---
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'curator_display_name',
        'email',
        'memrise_username', // H2054 — claim imported Memrise leaderboard points
        'password',
        'wants_email_announcements',
        'wants_messenger_announcements',
        'newsletter_subscribed_at',
        'is_admin',
        'role',
        'teacher_id',
        'is_lecture_editor',
        'telegram_id',           // <-- Добавили для Telegram
        'telegram_username',     // @username TG (без @); ловим при привязке/сообщении
        'telegram_auth_token',   // <-- Добавили для Telegram
        'telegram_connected_at', // когда привязал TG-бота
        'vk_id',
        'vk_auth_token',         // одноразовый токен привязки VK (см. VkController::connect)
        'vk_connected_at',       // когда привязал ВК-бота
        'avatar_path',           // путь к скачанной аватарке TG/VK (public-диск)
        'avatar_synced_at',      // когда последний раз синхронизировали аватарку
        'max_user_id',
        'instagram',
        'facebook',
        // --- НОВЫЕ ПОЛЯ ИЗ EXCEL ---
        'phone',
        'city',      // H3909 — спрашиваем у каждого ученика (MG 02-09-2026)
        'country',   // H3909 — спрашиваем у каждого ученика (MG 02-09-2026)
        'global_status',
        'note',
        'last_login_at',
        'cabinet_invite_sent_at',
        'last_activity_at',
        'last_login_ip',
        'login_count',
        'total_time_spent',
        'total_lessons_opened',
        'prana_balance',
        'referral_credit',
        'streak_days',
        'streak_last_day',
        // Надёжность: блокирует loyalty-скидку, обещания и conditional-доступ.
        'is_unreliable',
        'unreliable_reason',
        'unreliable_marked_at',
        'unreliable_marked_by',
        'unreliable_auto',
        'discipline_improved_since',
        // Атрибуция при регистрации (A1) — захват UTM/реферера на первом визите +
        // необязательное поле онбординга.
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'click_id',
        // HTTP-реферер регистрации. Имя http_referrer (а не referrer), чтобы не
        // затенять relation referrer() (BelongsTo через referred_by).
        'http_referrer',
        'lead_id',
        'birth_year',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // ВАЖНО: это ДОЛЖНО быть свойство `$casts`, а не метод `casts()`.
    // Метод `casts()` появился только в Laravel 11 — на Laravel 10 (этот проект,
    // 10.50.x) Eloquent читает исключительно свойство `$casts` (см. HasAttributes::getCasts),
    // поэтому метод молча игнорировался и НИ ОДИН каст не применялся (datetime приходили
    // строками, is_admin — строкой, password не хешировался кастом и т.п.).
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
        'wants_email_announcements' => 'boolean',
        'wants_messenger_announcements' => 'boolean',
        'newsletter_subscribed_at' => 'datetime',
        'is_lecture_editor' => 'boolean',
        'last_login_at' => 'datetime',
        'cabinet_invite_sent_at' => 'datetime',
        'welcome_email_sent_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'telegram_connected_at' => 'datetime',
        'vk_connected_at' => 'datetime',
        'avatar_synced_at' => 'datetime',
        'login_count' => 'integer',
        'total_time_spent' => 'integer',
        'total_lessons_opened' => 'integer',
        'prana_balance' => 'integer',
        'is_unreliable' => 'boolean',
        'unreliable_auto' => 'boolean',
        'unreliable_marked_at' => 'datetime',
        'discipline_improved_since' => 'date',
    ];

    /**
     * Нормализация email — единый источник правды для идентичности.
     *
     * Доступ студента привязан к оплатам, оплаты — к email. Раньше сравнение шло
     * по точной строке (`where('email', ...)`), поэтому `Anna@Mail.ru` и
     * `anna@mail.ru` считались РАЗНЫМИ людьми: старый студент не мог войти и
     * плодились дубли-сироты без курсов. Приводим к нижнему регистру + trim.
     * Локальная часть формально регистрозависима по RFC, но на практике все
     * провайдеры трактуют её регистронезависимо — лоуэркейс безопасен и стандартен.
     *
     * Мутатор ниже нормализует ВСЕ записи через Eloquent (create/update/fill/forceFill);
     * на стороне ЧТЕНИЯ те же правила применяет `normalizeEmail()` перед запросом
     * (login / сброс пароля / поиск на чекауте).
     */
    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);

        return $email === '' ? '' : mb_strtolower($email);
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = self::normalizeEmail($value);
    }

    /**
     * Подписан ли пользователь на рассылку (H324) — открывает «полку подписчика»
     * в кабинете. НЕ влияет на платный доступ.
     */
    public function isNewsletterSubscriber(): bool
    {
        return $this->newsletter_subscribed_at !== null;
    }

    /**
     * Нормализуем @username Telegram: убираем ведущий @ и пробелы.
     * Регистр НЕ трогаем — Telegram трактует username регистронезависимо,
     * но показываем как прислал клиент. Пустую строку считаем «нет username».
     */
    public static function normalizeTelegramUsername(?string $username): ?string
    {
        if ($username === null) {
            return null;
        }

        $username = trim($username);
        $username = preg_replace('~^https?://t\.me/~i', '', $username) ?? $username;
        $username = preg_replace('~^t\.me/~i', '', $username) ?? $username;
        $username = ltrim($username, '@');
        $username = trim($username, " \t\n\r\0\x0B/");

        return $username === '' ? null : $username;
    }

    /**
     * Публичный URL аватарки студента (скачанной из TG/VK) или null, если фото
     * нет — тогда во вьюхах показываем инициал-кружок. Единый источник правды
     * для всех мест отображения (helpdesk, карточка, Filament-аватар).
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    /** Filament подхватывает это для топбара/меню/таблиц (интерфейс HasAvatar). */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatarUrl();
    }

    /** Прямая ссылка на Telegram-профиль студента (или null, если @username не пойман). */
    public function telegramLink(): ?string
    {
        return $this->telegram_username ? 'https://t.me/'.$this->telegram_username : null;
    }

    /**
     * Запомнить @username из входящего апдейта бота, если он изменился.
     * Идемпотентно: ничего не пишет, когда username тот же или пустой.
     * Возвращает true, если запись обновлена.
     */
    public function rememberTelegramUsername(?string $username): bool
    {
        $normalized = self::normalizeTelegramUsername($username);

        if ($normalized === null || $normalized === $this->telegram_username) {
            return false;
        }

        $this->forceFill(['telegram_username' => $normalized])->save();

        return true;
    }

    public function isUnreliable(): bool
    {
        return (bool) $this->is_unreliable;
    }

    /**
     * Имя куратора, которое видит студент при ответе в чате: псевдоним, если задан,
     * иначе ФИО.
     */
    public function curatorDisplayName(): string
    {
        $alias = trim((string) $this->curator_display_name);

        return $alias !== '' ? $alias : (string) $this->name;
    }

    public function scopeUnreliable($query)
    {
        return $query->where('is_unreliable', true);
    }

    /**
     * Поднять флаг неблагонадёжности. `$auto=true` означает срабатывание
     * UnreliabilityAuditor по порогу просрочек; ручной marker оставляет $auto=false
     * — это нужно, чтобы при последующем «снять флаг» не было сомнений, кто его
     * выставил. Discipline_improved_since сбрасываем: новый отсчёт начнётся
     * после того, как поведение фактически исправится.
     */
    public function markUnreliable(string $reason, ?self $by = null, bool $auto = false): void
    {
        $this->forceFill([
            'is_unreliable' => true,
            'unreliable_reason' => $reason,
            'unreliable_marked_at' => now(),
            'unreliable_marked_by' => $by?->id,
            'unreliable_auto' => $auto,
            'discipline_improved_since' => null,
        ])->save();
    }

    public function clearUnreliable(?self $by = null): void
    {
        $this->forceFill([
            'is_unreliable' => false,
            'unreliable_reason' => null,
            'unreliable_marked_at' => null,
            'unreliable_marked_by' => $by?->id,
            'unreliable_auto' => false,
            'discipline_improved_since' => null,
        ])->save();
    }

    // ==========================================
    // ФЕЙСКОНТРОЛЬ В АДМИНКУ
    // ==========================================
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'editor' => $this->isAdminLike() || (bool) $this->is_lecture_editor,
            default => $this->isAdminLike() || $this->isTeacher() || $this->isManager() || $this->isAccountant(),
        };
    }

    // ==========================================
    // ХЕЛПЕРЫ РОЛЕЙ
    // ==========================================
    public function isSuperAdmin(): bool
    {
        return $this->role === Roles::SUPER_ADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === Roles::ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === Roles::TEACHER;
    }

    public function isManager(): bool
    {
        return $this->role === Roles::MANAGER;
    }

    public function isAccountant(): bool
    {
        return $this->role === Roles::ACCOUNTANT;
    }

    /**
     * super_admin или admin — то, что раньше понималось под is_admin.
     */
    public function isAdminLike(): bool
    {
        return in_array($this->role, Roles::adminLike(), true);
    }

    /**
     * Синхронизируем legacy-флаг is_admin с ролью.
     * Старый код (Payment.php, TrackUserActivity и т.п.) читает is_admin,
     * и пока его не выпиливаем — держим в актуальном состоянии.
     *
     * Гейт по isDirty('role'): иначе любой save() с явно заданным is_admin
     * (например, в legacy-сидере или фабрике) был бы молча перезатёрт,
     * потому что у свежесозданной записи role=null → is_admin вычисляется
     * как false. На новой модели isDirty('role') = true ровно когда role
     * передана в create()/fill() — тогда синхронизация уместна.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if ($user->isDirty('role')) {
                $user->is_admin = in_array($user->role, Roles::adminLike(), true);
            }
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    // ==========================================
    // ГРАНТ ПРОВЕРЯЮЩЕГО ДОМАШЕК (H1729)
    // ==========================================

    /**
     * Группы, домашки которых пользователь проверяет по гранту. Живёт отдельно
     * от преподавания: грант НЕ делает его преподавателем курса и не участвует
     * в Course::salaryTermsFor(), поэтому ЗП по нему не начисляется.
     */
    public function reviewedGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_reviewer')
            ->withPivot(['can_review', 'notify', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /** @var array<int>|null Мемо на инстанс — выборка дёргается на каждый рендер таблицы. */
    private ?array $reviewableGroupIdsMemo = null;

    /**
     * ID групп, домашки которых этот пользователь вправе видеть по гранту.
     * Пустой массив, когда фича выключена или грантов нет.
     *
     * @return array<int>
     */
    public function reviewableGroupIds(): array
    {
        if (! config('homework.reviewers.enabled')) {
            return [];
        }

        return $this->reviewableGroupIdsMemo ??= $this->reviewedGroups()
            ->pluck('groups.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Грант на группу есть и он позволяет выносить вердикт. */
    public function canReviewGroup(int $groupId): bool
    {
        if (! in_array($groupId, $this->reviewableGroupIds(), true)) {
            return false;
        }

        $grant = $this->reviewedGroups()->where('groups.id', $groupId)->first();

        return $grant !== null && (bool) $grant->pivot->can_review;
    }

    // ==========================================
    // СВЯЗИ ДЛЯ LMS (НЕ ТРОГАЕМ, ВСЁ БЕЗОПАСНО)
    // ==========================================
    public function groups(): BelongsToMany
    {
        // ВСЕ членства, включая «вышедших» (left_at != null). Это путь ДОСТУПА:
        // dashboard/showCourse/forUserGroups гейтят курс по этой связи — поэтому
        // «мягкий выход» из группы НЕ должен влиять на доступ к оплаченному.
        return $this->belongsToMany(Group::class)
            ->withPivot(['left_at', 'left_reason'])
            ->withTimestamps();
    }

    /**
     * «Активный состав» группы — только те членства, где студент не помечен
     * вышедшим (left_at IS NULL). Этим питаются ростеры/напоминания/чаты, НЕ доступ.
     */
    public function activeGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->withPivot(['left_at', 'left_reason'])
            ->withTimestamps()
            ->wherePivotNull('left_at');
    }

    /** Архив: группы, из которых студент выведен (выпустился/ушёл/исключён/вручную). */
    public function archivedGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->withPivot(['left_at', 'left_reason'])
            ->withTimestamps()
            ->wherePivotNotNull('left_at');
    }

    // Персональные скидки студента на курсы.
    public function individualDiscounts(): HasMany
    {
        return $this->hasMany(StudentDiscount::class);
    }

    /**
     * Реально пройденные уроки (is_completed=true). Используется и для
     * прогресс-баров в шаблонах, и для гейта повторного начисления праны.
     * Заметки сохраняются отдельной строкой пивота (см. lessonProgress),
     * поэтому без wherePivot('is_completed', true) сюда попадали бы черновики
     * заметок и ломали и счётчик course_complete, и идемпотентность.
     */
    public function completedLessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_user')
            ->wherePivot('is_completed', true)
            ->withPivot('notes', 'is_completed')
            ->withTimestamps();
    }

    /**
     * Прогресс по урокам без фильтра по is_completed: используется для
     * чтения/записи заметок и для апдейта pivot-строки при «отметить пройденным».
     */
    public function lessonProgress(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_user')
            ->withPivot('notes', 'is_completed')
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function homeworkSubmissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function feedTokens(): HasMany
    {
        return $this->hasMany(FeedToken::class);
    }

    /**
     * Активный токен персонального iCal/webcal-фида — лениво создаётся при
     * первом обращении (Google Calendar Phase 1,
     * docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md).
     */
    public function calendarFeedToken(): FeedToken
    {
        $token = $this->feedTokens()->whereNull('revoked_at')->latest()->first();

        return $token ?? $this->feedTokens()->create(['token' => FeedToken::generate()]);
    }

    // --- НОВАЯ СВЯЗЬ: Студент -> Курсы (со статусами) ---
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class)
            ->withPivot('status', 'note', 'left_after_block', 'joined_at_block')
            ->withTimestamps();
    }

    // ==========================================
    // ВОССТАНОВЛЕНИЕ ПАРОЛЯ
    // ==========================================
    // Переопределяем стандартное уведомление Laravel, чтобы письмо уходило
    // на русском в фирменном оформлении (через очередь 'mailing').
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)
            ->send(new PasswordResetMail($this, $token));
    }

    // ==========================================
    // ОТПРАВКА УВЕДОМЛЕНИЙ В TELEGRAM (Умная)
    // ==========================================
    public function sendTelegramMessage($text, $imagePath = null)
    {
        if (! $this->telegram_id) {
            return false;
        }

        // Личные уведомления студенту шлёт бот кабинета (привязка к нему же).
        // Фолбэк на основной бот, если отдельный не задан.
        $token = config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token');

        try {
            // Находим физический путь к картинке
            $absolutePath = $imagePath ? storage_path('app/public/'.$imagePath) : null;

            if ($absolutePath && file_exists($absolutePath)) {

                // Проверяем длину текста (лимит ТГ для картинок - 1024 символа)
                // Берем с запасом 1000, чтобы теги не сломались
                if (mb_strlen(strip_tags($text)) > 1000) {

                    // 1. Текст слишком длинный! Отправляем сначала просто КАРТИНКУ
                    Http::attach(
                        'photo', fopen($absolutePath, 'r'), basename($absolutePath)
                    )->post("https://api.telegram.org/bot{$token}/sendPhoto", [
                        'chat_id' => $this->telegram_id,
                    ]);

                    // 2. А следом отправляем ТЕКСТ
                    $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $this->telegram_id,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                    ]);

                } else {
                    // Текст короткий! Отправляем КАРТИНКУ ВМЕСТЕ С ТЕКСТОМ
                    $response = Http::attach(
                        'photo', fopen($absolutePath, 'r'), basename($absolutePath)
                    )->post("https://api.telegram.org/bot{$token}/sendPhoto", [
                        'chat_id' => $this->telegram_id,
                        'caption' => $text,
                        'parse_mode' => 'HTML',
                    ]);
                }

            } else {
                // КАРТИНКИ НЕТ - Отправляем обычный текст
                $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $this->telegram_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
            }

            // ЛОВИМ ОШИБКИ ТЕЛЕГРАМА (теперь они не пройдут незамеченными!)
            if ($response->failed()) {
                Log::error('Ошибка API ТГ: '.$response->body());

                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Критическая ошибка отправки в ТГ: '.$e->getMessage());

            return false;
        }
    }

    // ==========================================
    // ОТПРАВКА УВЕДОМЛЕНИЙ В VK (С КАРТИНКОЙ И ССЫЛКАМИ)
    // ==========================================
    public function sendVkMessage($text, $attachment = null)
    {
        if (empty($this->vk_id)) {
            Log::info("Пропуск ВК: У пользователя {$this->email} не заполнен vk_id в базе.");

            return false;
        }

        // env() в рантайме = null при закешированном конфиге (deploy.sh делает
        // optimize) — VK отвечал error 15 «token required». Только config().
        $token = config('services.vk.bot_token');

        try {
            // Формируем базовые параметры (Я УБРАЛ strip_tags, текст уже подготовлен в Job!)
            $params = [
                'user_id' => $this->vk_id,
                'message' => $text,
                'random_id' => random_int(1, 2147483647),
                'access_token' => $token,
                'v' => '5.131',
            ];

            // Если передали код вложения (картинку), добавляем его в запрос
            if ($attachment) {
                $params['attachment'] = $attachment;
            }

            $response = Http::asForm()->post('https://api.vk.com/method/messages.send', $params);
            $result = $response->json();

            if (isset($result['error'])) {
                Log::error('ВК АПИ ОШИБКА: '.json_encode($result['error'], JSON_UNESCAPED_UNICODE));

                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Критическая ошибка отправки в ВК: '.$e->getMessage());

            return false;
        }
    }

    // ==========================================
    // ОТПРАВКА SMS (SMS.ru, РЕЗЕРВНЫЙ КАНАЛ)
    // ==========================================
    public function sendSmsMessage(string $text): bool
    {
        if (empty($this->phone)) {
            Log::info("Пропуск SMS: У пользователя {$this->email} не заполнен phone в базе.");

            return false;
        }

        return app(SmsRuChannel::class)->send($this->phone, $text);
    }

    // ==========================================
    // СВЯЗЬ С ЧАТОМ (ДЛЯ HELPDESK)
    // ==========================================
    /** Привязанные внешние OAuth-аккаунты (Google/VK/Yandex). */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Ранг студента по накопленной пране (lifetime_prana) + прогресс к следующему.
     *
     * @return array{key: string, name: string, min: int, lifetime: int, next_name: ?string, next_min: ?int, progress: int}
     */
    public function pranaRank(): array
    {
        return PranaSettings::rankFor((int) ($this->lifetime_prana ?? 0));
    }

    // --- РЕФЕРАЛЬНАЯ ПРОГРАММА ---

    /** Кто пригласил этого студента. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /** Кого пригласил этот студент. */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    // --- АТРИБУЦИЯ (A1) ---

    /** Lead, из которого вырос этот аккаунт (мэтчинг по email при регистрации). */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Реферальный код студента — генерируется лениво при первом обращении. */
    public function referralCode(): string
    {
        if (blank($this->referral_code)) {
            do {
                $code = strtoupper(Str::random(8));
            } while (static::where('referral_code', $code)->exists());

            $this->forceFill(['referral_code' => $code])->save();
        }

        return $this->referral_code;
    }

    /** Публичная ссылка-приглашение. */
    public function referralLink(): string
    {
        return url('/?ref='.$this->referralCode());
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    /** Импортированные TG-support чаты, сведённые на этого пользователя. */
    public function linkedSupportChats()
    {
        return $this->hasMany(TelegramSupportChat::class, 'linked_user_id');
    }

    /** Операционные треды поддержки этого пользователя. */
    public function supportConversations(): HasMany
    {
        return $this->hasMany(SupportConversation::class);
    }

    /** Текущий (последний) тред поддержки — для фильтра вкладок Helpdesk. */
    public function latestSupportConversation(): HasOne
    {
        return $this->hasOne(SupportConversation::class)->latestOfMany();
    }

    public function paymentPromises(): HasMany
    {
        return $this->hasMany(PaymentPromise::class);
    }

    public function lessonAccessGrants(): HasMany
    {
        return $this->hasMany(LessonAccessGrant::class)->orderByDesc('granted_at');
    }

    /**
     * Все сессии пользователя.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class)->orderByDesc('started_at');
    }

    /**
     * Текущая активная сессия (если есть).
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(UserSession::class)->where('is_active', true)->latestOfMany('started_at');
    }

    /**
     * Все просмотры уроков.
     */
    public function lessonViews(): HasMany
    {
        return $this->hasMany(LessonView::class);
    }

    /**
     * Сырые события активности.
     */
    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    /**
     * История начислений и списаний праны.
     */
    public function pranaTransactions(): HasMany
    {
        return $this->hasMany(PranaTransaction::class)->orderByDesc('created_at');
    }

    /**
     * Проверка: онлайн ли студент сейчас.
     * Онлайн = была активность не более 5 минут назад.
     */
    public function isOnline(): bool
    {
        if (! $this->last_activity_at) {
            return false;
        }

        // last_activity_at теперь кастуется в Carbon (см. $casts); parse оставлен
        // как дешёвая защита на случай сырой строки — Carbon::parse(Carbon) идемпотентен.
        return Carbon::parse($this->last_activity_at)->gt(now()->subMinutes(5));
    }
}
