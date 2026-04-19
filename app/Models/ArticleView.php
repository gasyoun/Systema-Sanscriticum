<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleView extends Model
{
    /**
     * В таблице только created_at — updated_at нам не нужен.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'article_id',
        'visitor_hash',
        'ip',
        'referrer',
        'user_agent',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}