<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarTopicSource extends Model
{
    protected $fillable = [
        'grammar_topic_id',
        'topic_id',
        'family',
        'anchor_type',
        'anchor_id',
        'anchor_key_slp1',
        'source_dataset',
        'link_type',
        'confidence',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'confidence' => 'float',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(GrammarTopic::class, 'grammar_topic_id');
    }
}
