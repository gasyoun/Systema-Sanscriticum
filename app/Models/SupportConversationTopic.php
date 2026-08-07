<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One topic-assignment event on an operational SupportConversation (H2381).
 * Append-only history; current topic is the latest row for the conversation.
 * Taxonomy categories come from SupportTopicRule (+ explicit other/uncategorized).
 */
class SupportConversationTopic extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_KEYWORD = 'keyword';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORY_UNCATEGORIZED = 'uncategorized';

    protected $fillable = [
        'support_conversation_id',
        'category',
        'source',
        'assigned_by',
        'reason',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
