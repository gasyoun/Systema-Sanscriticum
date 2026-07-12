<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт-черновик ответа на FAQ студента (категории A «Zoom/ссылка», B «записи»,
 * C «расписание»), собранный БЕЗ единого LLM-вызова из данных LMS (Schedule/Lesson).
 * Сам ничего не отправляет — куратор в Helpdesk жмёт Принять/Изменить/Отклонить.
 * См. H247 (тикет S3 support-roadmap).
 */
class SupportAnswerSuggestion extends Model
{
    public const SOURCE_CHAT_MESSAGE = 'chat_message';

    public const SOURCE_TELEGRAM_SUPPORT_MESSAGE = 'telegram_support_message';

    // Категории FAQ-БОТ (таксономия telegram-zabota-export/ANALYSIS.md).
    public const CATEGORY_ZOOM = 'A';       // Zoom / ссылка / подключение

    public const CATEGORY_RECORDING = 'B';  // записи / видео / тайм-коды

    public const CATEGORY_SCHEDULE = 'C';   // расписание / время / переносы

    // v2 (S5, H816) — резолвятся LLM-ом (SupportAnswerAiResolver), а не шаблоном.
    public const CATEGORY_PRICE = 'D';      // оплата / цена / тарифы

    public const CATEGORY_ACCESS = 'E';     // доступ / группа / личный кабинет

    public const CATEGORY_MATERIALS = 'F';  // материалы / ДЗ / сертификаты

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EDITED = 'edited';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'category',
        'detected_text',
        'draft_text',
        'facts',
        'confidence',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'facts' => 'array',
        'confidence' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
