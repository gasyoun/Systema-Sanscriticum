<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarTopicVersion extends Model
{
    protected $fillable = [
        'grammar_topic_id',
        'topic_id',
        'content_hash',
        'status',
        'import_id',
        'payload',
        'recorded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(GrammarTopic::class, 'grammar_topic_id');
    }
}
