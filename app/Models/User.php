<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// --- ДОБАВЛЯЕМ КЛАССЫ ДЛЯ ЗАЩИТЫ FILAMENT ---
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

// --- УКАЗЫВАЕМ, ЧТО ЮЗЕР ИСПОЛЬЗУЕТ ИНТЕРФЕЙС FILAMENT ---
class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'telegram_id',           // <-- Добавили для Telegram
        'telegram_auth_token',   // <-- Добавили для Telegram
        'vk_id',
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
        ];
    }

    // ==========================================
    // ФЕЙСКОНТРОЛЬ В АДМИНКУ
    // ==========================================
    public function canAccessPanel(Panel $panel): bool
    {
        $safeEmail = trim(strtolower($this->email));
        return $this->is_admin || $safeEmail === 'pe4kinsmart@gmail.com';
    }

    // ==========================================
    // СВЯЗИ ДЛЯ LMS (НЕ ТРОГАЕМ, ВСЁ БЕЗОПАСНО)
    // ==========================================
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    public function completedLessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_user')
                    ->withPivot('notes')
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

    // ==========================================
    // ОТПРАВКА УВЕДОМЛЕНИЙ В TELEGRAM (Умная)
    // ==========================================
    public function sendTelegramMessage($text, $imagePath = null)
    {
        if (!$this->telegram_id) {
            return false;
        }

        $token = env('TELEGRAM_BOT_TOKEN');
        
        try {
            // Находим физический путь к картинке
            $absolutePath = $imagePath ? storage_path('app/public/' . $imagePath) : null;

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
                \Illuminate\Support\Facades\Log::error('Ошибка API ТГ: ' . $response->body());
                return false;
            }

            return true;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Критическая ошибка отправки в ТГ: ' . $e->getMessage());
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
                'v' => '5.131'
            ];

            // Если передали код вложения (картинку), добавляем его в запрос
            if ($attachment) {
                $params['attachment'] = $attachment;
            }

            $response = \Illuminate\Support\Facades\Http::asForm()->post("https://api.vk.com/method/messages.send", $params);
            $result = $response->json();
            
            if (isset($result['error'])) {
                \Illuminate\Support\Facades\Log::error('ВК АПИ ОШИБКА: ' . json_encode($result['error'], JSON_UNESCAPED_UNICODE));
                return false;
            }

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Критическая ошибка отправки в ВК: ' . $e->getMessage());
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
}