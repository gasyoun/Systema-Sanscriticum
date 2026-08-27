<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TelegramSupportContact extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'telegram_support_chat_id',
        'linked_user_id',
        'name',
        'username',
        'first_seen_at',
        'first_inbound_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'first_inbound_at' => 'datetime',
        'link_token_expires_at' => 'datetime',
        'link_invited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $contact): void {
            if (! $contact->linked_user_id || ! $contact->telegram_support_chat_id) {
                return;
            }

            $chat = $contact->chat;
            // Multi-user groups: не перетирать chat.linked_user_id последним отправителем.
            // Линк sender→User живёт на contact; chat.linked_user_id — только private.
            if ($chat
                && ($chat->type === null || $chat->type === 'private')
                && $chat->linked_user_id !== $contact->linked_user_id) {
                $chat->forceFill(['linked_user_id' => $contact->linked_user_id])->save();
            }
        });
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportChat::class, 'telegram_support_chat_id');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    /**
     * H3542: выпустить capability-токен ссылки-приглашения. Гарантии те же,
     * что у MagicLinkToken: plaintext уходит в DM, в БД только SHA-256 hash;
     * выпуск нового токена затирает прежний — старая ссылка гаснет сразу.
     *
     * @return string PLAINTEXT для URL
     */
    public function issueLinkToken(int $ttlHours): string
    {
        $plaintext = Str::random(48);

        $this->forceFill([
            'link_token_hash' => hash('sha256', $plaintext),
            'link_token_expires_at' => now()->addHours(max(1, $ttlHours)),
        ])->save();

        return $plaintext;
    }

    /**
     * Живой (несвязанный, непротухший) контакт по plaintext-токену. Не различаем
     * «нет/протух/использован» снаружи — вызывающий отдаёт 404 uniformly.
     */
    public static function findActiveByLinkToken(string $plaintext): ?self
    {
        if ($plaintext === '') {
            return null;
        }

        /** @var self|null $contact */
        $contact = self::query()
            ->where('link_token_hash', hash('sha256', $plaintext))
            ->first();

        if ($contact === null
            || $contact->linked_user_id !== null
            || $contact->link_token_expires_at === null
            || $contact->link_token_expires_at->isPast()
        ) {
            return null;
        }

        return $contact;
    }

    /** Погасить токен после успешного связывания (или вручную). */
    public function consumeLinkToken(): void
    {
        $this->forceFill(['link_token_hash' => null, 'link_token_expires_at' => null])->save();
    }
}
