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

// --- УКАЗЫВАЕМ, ЧТО ЮЗЕР ИСПОЛЬЗУЕТ ИНТЕРФЕЙС FILAMENT ---
class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'role',
        'teacher_id',
        'is_lecture_editor',
        'telegram_id',           // <-- Добавили для Telegram
        'telegram_auth_token',   // <-- Добавили для Telegram
        'vk_id',
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
            'is_lecture_editor' => 'boolean',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'login_count' => 'integer',
            'total_time_spent' => 'integer',
            'total_lessons_opened' => 'integer',
            'prana_balance' => 'integer',
        ];
    }

    // ==========================================
    // ФЕЙСКОНТРОЛЬ В АДМИНКУ
    // ==========================================
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'editor' => $this->isAdminLike() || (bool) $this->is_lecture_editor,
            default => $this->isAdminLike() || $this->isTeacher() || $this->isManager(),
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

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    // --- НОВАЯ СВЯЗЬ: Студент -> Курсы (со статусами) ---
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class)
            ->withPivot('status', 'note')
            ->withTimestamps();
    }

    // ==========================================
    // ОТПРАВКА УВЕДОМЛЕНИЙ В TELEGRAM (Умная)
    // ==========================================
    public function sendTelegramMessage($text, $imagePath = null)
    {
        if (! $this->telegram_id) {
            return false;
        }

        $token = env('TELEGRAM_BOT_TOKEN');

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
    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function paymentPromises(): HasMany
    {
        return $this->hasMany(PaymentPromise::class);
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
        return $this->last_activity_at !== null
            && $this->last_activity_at->gt(now()->subMinutes(5));
    }
}
