<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5773 — замороженная выборка gold-ревью (100 сообщений H5709).
 *
 * Состав неизменяем после заморозки: члены — в items с снапшотом предсказания,
 * повторная заморозка того же состава блокируется уникальным fingerprint.
 * Метки можно писать только пока статус open и версия классификатора совпадает
 * с текущей (гард в GoldReviewService, не в схеме).
 */
class SupportQuestionReviewSample extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'window_from',
        'window_to',
        'classifier_version',
        'sample_size',
        'fingerprint',
        'status',
        'created_by',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'sample_size' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SupportQuestionReviewItem::class, 'sample_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(SupportQuestionReviewLabel::class, 'sample_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function windowLabel(): string
    {
        return $this->window_from.'..'.$this->window_to;
    }
}
