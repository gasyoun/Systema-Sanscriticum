<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H5773 — gold-метка ревьюера по одному item выборки.
 *
 * Одна живая метка на item (unique item_id): повторный сабмит обновляет
 * метку, а не создаёт дубль, поэтому завершение честно требует 100
 * РАЗЛИЧНЫХ размеченных строк. user_id — провенанс ревьюера.
 */
class SupportQuestionReviewLabel extends Model
{
    protected $fillable = [
        'sample_id',
        'item_id',
        'user_id',
        'gold_label',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(SupportQuestionReviewItem::class, 'item_id');
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(SupportQuestionReviewSample::class, 'sample_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
