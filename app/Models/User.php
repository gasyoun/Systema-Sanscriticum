<?php

namespace App\Models;

use App\Support\Roles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
// --- ДОБАВЛЯЕМ КЛАССЫ ДЛЯ ЗАЩИТЫ FILAMENT ---
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// --- УКАЗЫВАЕМ, ЧТО ЮЗЕР ИСПОЛЬЗУЕТ ИНТЕРФЕЙС FILAMENT ---
class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'curator_display_name',
        'email',
        'password',
        'wants_email_announcements',
        'is_admin',
        'role',
        'teacher_id',
        'is_lecture_editor',
        'telegram_id',           // <-- Добавили для Telegram
        'telegram_auth_token',   // <-- Добавили для Telegram
        'telegram_connected_at', // когда привязал TG-бота
        'vk_id',
        'vk_connected_at',       // когда привязал ВК-бота
        'max_user_id',
        'instagram',
        'facebook',
        // --- НОВЫЕ ПОЛЯ ИЗ EXCEL ---
        'phone',
        'global_status',
        'note',
        'last_login_at',
        'last_activity_at',
        'last_login_ip',
        'login_count',
        'total_time_spent',
        'total_lessons_opened',
        'prana_balance',
        // Надёжность: блокирует loyalty-скидку, обещания и conditional-доступ.
        'is_unreliable',
        'unreliable_reason',
        'unreliable_marked_at',
        'unreliable_marked_by',
        'unreliable_auto',
        'discipline_improved_since',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'wants_email_announcements' => 'boolean',
            'is_lecture_editor' => 'boolean',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'telegram_connected_at' => 'datetime',
            'vk_connected_at' => 'datetime',
            'login_count' => 'integer',
            'total_time_spent' => 'integer',
            'total_lessons_opened' => 'integer',
            'prana_balance' => 'integer',
            'is_unreliable' => 'boolean',
            'unreliable_auto' => 'boolean',
            'unreliable_marked_at' => 'datetime',
            'discipline_improved_since' => 'date',
        ];
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
    // СВЯЗИ ДЛЯ LMS (НЕ ТРОГАЕМ, ВСЁ БЕЗОПАСНО)
    // ==========================================
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    // Персональные скидки студента на курсы.
    public function individualDiscounts(): \Illuminate\Database\Eloquent\Relations\HasMany
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
        \Illuminate\Support\Facades\Mail::to($this->email)
            ->send(new \App\Mail\PasswordResetMail($this, $token));
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
                    \Illuminate\Support\Facades\Http::attach(
                        'photo', fopen($absolutePath, 'r'), basename($absolutePath)
                    )->post("https://api.telegram.org/bot{$token}/sendPhoto", [
                        'chat_id' => $this->telegram_id,
                    ]);

                    // 2. А следом отправляем ТЕКСТ
                    $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $this->telegram_id,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                    ]);

                } else {
                    // Текст короткий! Отправляем КАРТИНКУ ВМЕСТЕ С ТЕКСТОМ
                    $response = \Illuminate\Support\Facades\Http::attach(
                        'photo', fopen($absolutePath, 'r'), basename($absolutePath)
                    )->post("https://api.telegram.org/bot{$token}/sendPhoto", [
                        'chat_id' => $this->telegram_id,
                        'caption' => $text,
                        'parse_mode' => 'HTML',
                    ]);
                }

            } else {
                // КАРТИНКИ НЕТ - Отправляем обычный текст
                $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $this->telegram_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
            }

            // ЛОВИМ ОШИБКИ ТЕЛЕГРАМА (теперь они не пройдут незамеченными!)
            if ($response->failed()) {
                \Illuminate\Support\Facades\Log::error('Ошибка API ТГ: '.$response->body());

                return false;
            }

            return true;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Критическая ошибка отправки в ТГ: '.$e->getMessage());

            return false;
        }
    }

    // ==========================================
    // ОТПРАВКА УВЕДОМЛЕНИЙ В VK (С КАРТИНКОЙ И ССЫЛКАМИ)
    // ==========================================
    public function sendVkMessage($text, $attachment = null)
    {
        if (empty($this->vk_id)) {
            \Illuminate\Support\Facades\Log::info("Пропуск ВК: У пользователя {$this->email} не заполнен vk_id в базе.");

            return false;
        }

        $token = env('VK_BOT_TOKEN');

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

            $response = \Illuminate\Support\Facades\Http::asForm()->post('https://api.vk.com/method/messages.send', $params);
            $result = $response->json();

            if (isset($result['error'])) {
                \Illuminate\Support\Facades\Log::error('ВК АПИ ОШИБКА: '.json_encode($result['error'], JSON_UNESCAPED_UNICODE));

                return false;
            }

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Критическая ошибка отправки в ВК: '.$e->getMessage());

            return false;
        }
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
        return \App\Services\Prana\PranaSettings::rankFor((int) ($this->lifetime_prana ?? 0));
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

    /** Реферальный код студента — генерируется лениво при первом обращении. */
    public function referralCode(): string
    {
        if (blank($this->referral_code)) {
            do {
                $code = strtoupper(\Illuminate\Support\Str::random(8));
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
    public function sessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserSession::class)->orderByDesc('started_at');
    }

    /**
     * Текущая активная сессия (если есть).
     */
    public function activeSession(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserSession::class)->where('is_active', true)->latestOfMany('started_at');
    }

    /**
     * Все просмотры уроков.
     */
    public function lessonViews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LessonView::class);
    }

    /**
     * Сырые события активности.
     */
    public function activityEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    /**
     * История начислений и списаний праны.
     */
    public function pranaTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
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

        // Carbon::parse устойчив к строке: на проде метод casts() не применяется
        // (Laravel 10.50), поэтому last_activity_at может прийти строкой, а не Carbon.
        return \Illuminate\Support\Carbon::parse($this->last_activity_at)->gt(now()->subMinutes(5));
    }
}
