<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class MarketingSetting extends Model
{
    private const CACHE_KEY = 'marketing_setting.singleton';

    use HasFactory;

    protected $fillable = [
        'is_loyalty_active',
        'deposit_enabled',
        'bundle_2_discount',
        'bundle_3_discount',
        'wholesale_small_threshold',
        'wholesale_small_discount',
        'wholesale_large_threshold',
        'wholesale_large_discount',
        'blog_yandex_metrika_id',
        'blog_vk_pixel_id',
        // --- ПРАНА ---
        'is_prana_active',
        'prana_rate',
        'prana_max_share_percent',
        'prana_reward_lesson_complete',
        'prana_reward_course_complete',
        'prana_reward_open_lesson_view',
        'prana_reward_daily_login',
        'prana_reward_payment_success',

        // --- LEAD MAGNET BOTS ---
        'magnet_delivery_mode',
        'tg_bot_username',
        'tg_bot_token',
        'tg_webhook_secret',
        'vk_group_screen_name',
        'vk_group_id',
        'vk_access_token',
        'vk_callback_secret',
        'vk_confirmation_code',
        'vk_confirmation_completed_at',
        'max_bot_username',
        'max_bot_token',
        'max_webhook_secret',

        // --- ТЕХОБСЛУЖИВАНИЕ КАБИНЕТА ---
        'student_maintenance_enabled',
        'student_maintenance_message',
        'student_maintenance_secret',

        // --- БОТЫ-КУРАТОРЫ В КАБИНЕТЕ ---
        'student_telegram_bot_enabled',
        'student_vk_bot_enabled',

        // --- АВТО-УВЕДОМЛЕНИЯ СТУДЕНТАМ (TG/VK) ---
        'payment_reminders_enabled',
        'class_reminders_enabled',
        'payment_reminder_time',
        'class_reminder_lead_minutes',
        'absent_notify_enabled',
        'absent_notify_delay_minutes',
        'debt_reminders_enabled',
        'debt_reminder_lead_days',
        'debt_reminder_cadence_days',
        'debt_reminder_to_telegram',
        'debt_reminder_to_vk',
        'debt_reminder_to_email',
        'debt_reminder_subject',
        'debt_reminder_text',

        // --- ДОЛЖНИКИ ---
        'debtors_notify_years',

        // --- ЗАРПЛАТЫ ПРЕПОДАВАТЕЛЕЙ ---
        'reference_teacher_percent',
    ];

    protected $casts = [
        'is_loyalty_active' => 'boolean',
        'reference_teacher_percent' => 'float',
        'deposit_enabled' => 'boolean',
        'bundle_2_discount' => 'integer',
        'bundle_3_discount' => 'integer',
        'wholesale_small_threshold' => 'integer',
        'wholesale_small_discount' => 'integer',
        'wholesale_large_threshold' => 'integer',
        'wholesale_large_discount' => 'integer',
        'is_prana_active' => 'boolean',
        'student_maintenance_enabled' => 'boolean',
        'student_telegram_bot_enabled' => 'boolean',
        'student_vk_bot_enabled' => 'boolean',
        'payment_reminders_enabled' => 'boolean',
        'class_reminders_enabled' => 'boolean',
        'class_reminder_lead_minutes' => 'integer',
        'absent_notify_enabled' => 'boolean',
        'absent_notify_delay_minutes' => 'integer',
        'debt_reminders_enabled' => 'boolean',
        'debt_reminder_lead_days' => 'integer',
        'debt_reminder_cadence_days' => 'integer',
        'debt_reminder_to_telegram' => 'boolean',
        'debt_reminder_to_vk' => 'boolean',
        'debt_reminder_to_email' => 'boolean',
        'debtors_notify_years' => 'array',
        'prana_rate' => 'integer',
        'prana_max_share_percent' => 'integer',
        'prana_reward_lesson_complete' => 'integer',
        'prana_reward_course_complete' => 'integer',
        'prana_reward_open_lesson_view' => 'integer',
        'prana_reward_daily_login' => 'integer',
        'prana_reward_payment_success' => 'integer',

        'vk_confirmation_completed_at' => 'datetime',

        // Bot-токены и webhook-секреты шифруются на уровне модели —
        // в БД лежит шифр, не plaintext. Без этого утечка дампа БД
        // даёт атакующему возможность подделывать webhook-запросы.
        'tg_bot_token' => 'encrypted',
        'tg_webhook_secret' => 'encrypted',
        'vk_access_token' => 'encrypted',
        'vk_callback_secret' => 'encrypted',
        'max_bot_token' => 'encrypted',
        'max_webhook_secret' => 'encrypted',
    ];

    /**
     * Кэш singleton'а через Laravel Cache. Lead-magnet webhook-хоты обращаются
     * сюда 3-5 раз за реквест (middleware + контроллер + конструкторы
     * channel-сервисов) — без кэша это лишние SELECT'ы на критичном пути.
     *
     * В отличие от per-request статики, общий cache-store инвалидируется
     * между процессами: при сохранении из админки `saved`-listener дёргает
     * forget, и все live-воркеры Horizon при следующем `cached()` увидят
     * свежие настройки. Per-request статика этим не обладала.
     */
    public static function cached(): ?self
    {
        // Не кэшируем отсутствие строки навсегда: иначе на «холодном» инстансе
        // (строки настроек ещё нет / создана в обход Eloquent — сидер/прямой SQL)
        // в кэш попадёт null навсегда, и подсистемы (депозит/боты/магниты) молча
        // выключатся до ручного сброса. Кэшируем только реально существующую строку.
        if ($cached = Cache::get(self::CACHE_KEY)) {
            return $cached;
        }

        $settings = static::first();

        if ($settings !== null) {
            Cache::forever(self::CACHE_KEY, $settings);
        }

        return $settings;
    }

    public static function flushCached(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Возвращает список каналов, у которых **полностью** заполнена конфигурация
     * (username/screen_name + token). Используется на thank-you, чтобы показать
     * кнопки только для тех мессенджеров, в которые юзер реально сможет получить
     * файл — иначе deep-link открывает «мёртвый» бот.
     *
     * @return array<int, string> Подмножество ['telegram', 'vk', 'max']
     */
    public function configuredChannels(): array
    {
        $channels = [];

        if (! empty($this->tg_bot_username) && ! empty($this->tg_bot_token)) {
            $channels[] = 'telegram';
        }
        if (! empty($this->vk_group_screen_name) && ! empty($this->vk_access_token)) {
            $channels[] = 'vk';
        }
        if (! empty($this->max_bot_username) && ! empty($this->max_bot_token)) {
            $channels[] = 'max';
        }

        return $channels;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCached());
        static::deleted(fn () => self::flushCached());
    }
}
