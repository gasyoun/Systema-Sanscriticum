<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Services\Support\SupportAnswerFactResolver;
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

    // Категории FAQ-суггестера v2 (S5) — черновик формулирует LLM по фактам LMS.
    public const CATEGORY_PAYMENT = 'D';    // оплата / цена / тарифы / рассрочка

    public const CATEGORY_ACCESS = 'E';     // доступ / группа / личный кабинет

    public const CATEGORY_MATERIALS = 'F';  // материалы / ДЗ / сертификаты

    /** Категории, чей черновик формулирует LLM (а не строковый шаблон A/B/C). */
    public const LLM_CATEGORIES = [self::CATEGORY_PAYMENT, self::CATEGORY_ACCESS, self::CATEGORY_MATERIALS];

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

    /**
     * H3999, рулинг A1: политика отправки, записанная в черновик резолвером.
     *
     * Живёт в `facts`, а не в отдельной колонке, потому что описывает ИМЕННО
     * этот текст: категорию куратор в админке поменять может, а метка уезжает
     * вместе с черновиком, который она описывает. Черновики, заведённые до
     * H3999, метки не несут — они все из FAQ и потому «auto».
     */
    public function sendPolicy(): string
    {
        $facts = is_array($this->facts) ? $this->facts : [];

        return (string) ($facts['send_policy'] ?? SupportAnswerFactResolver::POLICY_AUTO);
    }

    /**
     * Черновик, который НИКОГДА не уходит студенту одним нажатием: деньги,
     * доступ, сертификат (рулинг A1) и эскалации.
     *
     * Две защёлки, и вторая не лишняя: метку политики можно не проставить
     * (черновик до H3999, ручная правка), а тип факта из денежного списка
     * запрещает отправку сам по себе.
     */
    public function isDraftOnly(): bool
    {
        if ($this->sendPolicy() !== SupportAnswerFactResolver::POLICY_AUTO) {
            return true;
        }

        $facts = is_array($this->facts) ? $this->facts : [];

        return in_array(
            (string) ($facts['fact_type'] ?? ''),
            SupportAnswerFactResolver::NEVER_AUTO_TYPES,
            true,
        );
    }
}
