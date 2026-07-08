<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\GoalCheckinService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Цель делегированного лида на период (H376). Прогресс считается и логируется
 * через {@see GoalCheckinService}, не здесь — модель хранит
 * только вход, не выводит светофор/процент сама.
 */
class Goal extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DONE = 'done';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'user_id',
        'title',
        'target_metric',
        'starting_value',
        'target_value',
        'period_start',
        'period_end',
        'status',
    ];

    protected $casts = [
        'starting_value' => 'decimal:2',
        'target_value' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(GoalCheckin::class);
    }
}
