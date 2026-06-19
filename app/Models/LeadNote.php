<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись истории общения с лидом: ручная заметка менеджера либо
 * автолог исходящей коммуникации (письмо / сообщение в мессенджер).
 */
class LeadNote extends Model
{
    use HasFactory;

    public const TYPE_NOTE = 'note';

    public const TYPE_EMAIL = 'email';

    public const TYPE_DM = 'dm';

    public const TYPES = [
        self::TYPE_NOTE => 'Заметка',
        self::TYPE_EMAIL => 'Письмо',
        self::TYPE_DM => 'Сообщение',
    ];

    protected $fillable = [
        'lead_id',
        'user_id',
        'type',
        'channel',
        'subject',
        'body',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
