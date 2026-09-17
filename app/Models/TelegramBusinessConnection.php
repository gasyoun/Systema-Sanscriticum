<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H5065 — подключение бота к аккаунту Telegram Business.
 *
 * Одна строка = одно `business_connection_id`, выданное Telegram при
 * подключении бота в «Business → Чат-боты» (в @BotFather для этого включается
 * **Secretary Mode**). Именно этот id нужен в sendMessage, чтобы сообщение ушло
 * ОТ ИМЕНИ аккаунта, а не от бота.
 *
 * Право ответа (`can_reply`) приходит внутри объекта `rights`
 * (BusinessBotRights) — по документации Business-ботов проверять нужно именно
 * его: «check your bot's permissions in the rights field … including can_reply
 * for sending and editing messages in private chats with incoming messages in
 * the last 24 hours». Верхнеуровневое поле того же имени остаётся фолбэком для
 * старых payload'ов.
 *
 * @property int $id
 * @property string $business_connection_id
 * @property int $owner_telegram_user_id
 * @property int|null $owner_chat_id
 * @property bool $can_reply
 * @property bool $is_enabled
 * @property array<string, mixed>|null $rights
 * @property Carbon|null $connected_at
 * @property Carbon|null $disabled_at
 */
class TelegramBusinessConnection extends Model
{
    protected $fillable = [
        'business_connection_id',
        'owner_telegram_user_id',
        'owner_chat_id',
        'can_reply',
        'is_enabled',
        'rights',
        'connected_at',
        'disabled_at',
    ];

    protected $casts = [
        'owner_telegram_user_id' => 'integer',
        'owner_chat_id' => 'integer',
        'can_reply' => 'boolean',
        'is_enabled' => 'boolean',
        'rights' => 'array',
        'connected_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    /**
     * Подключение, которым можно отправить сообщение от имени аккаунта:
     * живое и с правом ответа. Право отзывается владельцем в любой момент, а
     * `is_enabled=false` приходит тем же апдейтом — поэтому проверяются оба
     * поля, а не только наличие строки.
     */
    public static function usable(string $businessConnectionId): ?self
    {
        return self::query()
            ->where('business_connection_id', $businessConnectionId)
            ->where('is_enabled', true)
            ->where('can_reply', true)
            ->first();
    }
}
