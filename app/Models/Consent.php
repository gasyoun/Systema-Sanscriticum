<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна запись журнала согласий (152-ФЗ). Append-only: пишется только через
 * App\Services\Consent\ConsentRecorder, никогда не обновляется.
 */
class Consent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_PD = 'pd';

    public const TYPE_PROMO = 'promo';

    public const TYPE_MEDIA = 'media';

    public const TYPE_COOKIES = 'cookies';

    /**
     * Участие в тесте ИИ-помощника занятия: разбор вопросов ученика и его
     * сообщений об ошибках, чтобы править ответы помощника. Текст согласия —
     * страница /lesson-ai/consent (та же, что открывается из письма), поэтому
     * doc_version ссылается на дату редакции этого текста.
     */
    public const TYPE_AI_TEST = 'ai_test';

    public const ACTION_GIVEN = 'given';

    public const ACTION_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'user_id',
        'lead_id',
        'email',
        'type',
        'action',
        'doc_version',
        'source',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
