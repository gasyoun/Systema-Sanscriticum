<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * H5773 — член замороженной выборки gold-ревью.
 *
 * predicted_primary — снапшот на момент заморозки: ревью-экран показывает
 * его ТОЛЬКО после записи gold-метки по этому item (слепая разметка).
 * Текст сообщения не хранится — только живая связь до classification.
 */
class SupportQuestionReviewItem extends Model
{
    protected $fillable = [
        'sample_id',
        'classification_id',
        'population',
        'predicted_primary',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(SupportQuestionReviewSample::class, 'sample_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(SupportQuestionClassification::class, 'classification_id');
    }

    public function label(): HasOne
    {
        return $this->hasOne(SupportQuestionReviewLabel::class, 'item_id');
    }

    /**
     * Текст сообщения живой связью (без копирования в ревью-таблицы).
     */
    public function messageText(): ?string
    {
        return $this->classification?->message?->text;
    }
}
