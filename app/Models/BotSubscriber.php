<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Чат, нажавший /start в @samskrte_bot (или лид магнита) — аудитория карточек открытых занятий. */
class BotSubscriber extends Model
{
    public const SOURCE_LEAD = 'lead';

    public const SOURCE_START = 'start';

    protected $fillable = [
        'chat_id',
        'source',
        'first_name',
        'menu_sent_at',
        'blocked_at',
    ];

    protected $casts = [
        'menu_sent_at' => 'datetime',
        'blocked_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('blocked_at');
    }
}
