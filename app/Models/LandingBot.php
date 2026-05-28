<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Отдельный Telegram-бот лид-магнита, привязанный к одному лендингу.
 *
 * Архитектура «свой бот на лендинг»: Laravel владеет webhook каждого бота
 * (приём через /api/webhooks/telegram-magnet/{webhook_key}), отдаёт магнит и
 * трекает выдачу, а все прочие апдейты форвардит в n8n (анкета + прогрев).
 *
 * token/secret шифруются в БД (encrypted cast), как и в MarketingSetting.
 */
class LandingBot extends Model
{
    protected $fillable = [
        'landing_page_id',
        'webhook_key',
        'is_active',
        'tg_bot_username',
        'tg_bot_token',
        'tg_webhook_secret',
        'n8n_forward_url',
        'vk_is_active',
        'vk_n8n_forward_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'vk_is_active' => 'boolean',
        'tg_bot_token' => 'encrypted',
        'tg_webhook_secret' => 'encrypted',
    ];

    protected static function booted(): void
    {
        // webhook_key генерируем автоматически и неизменно — он зашит в URL вебхука.
        static::creating(function (LandingBot $bot) {
            if (empty($bot->webhook_key)) {
                $bot->webhook_key = Str::random(40);
            }
        });
    }

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    /** Полный URL вебхука этого бота (на него регистрируется setWebhook). */
    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/telegram-magnet/'.$this->webhook_key;
    }

    /** Готов ли бот принимать/отдавать (есть username + token). */
    public function isUsable(): bool
    {
        return $this->is_active
            && ! empty($this->tg_bot_username)
            && ! empty($this->tg_bot_token);
    }

    /** Deep-link для запуска бота с magnet-токеном. */
    public function deepLink(string $token): string
    {
        return "https://t.me/{$this->tg_bot_username}?start={$token}";
    }

    /** Настроен ли форвард VK-событий этого лендинга в n8n. */
    public function isVkForwardEnabled(): bool
    {
        return $this->vk_is_active && ! empty($this->vk_n8n_forward_url);
    }
}
