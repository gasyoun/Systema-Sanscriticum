<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал попыток win-back «уснувшего» студента из раздела «Реактивация» (H221).
 * Раньше страница была read-only и куратор копировал текст руками — теперь
 * фиксируем факт отправки шаблона, чтобы одного и того же «призрака» не
 * дёргать повторно (дедуп в окне 24 ч, см. WORDS ниже).
 */
class DebtWinBackAttempt extends Model
{
    use HasFactory;

    /** Окно, в котором повторную отправку тому же студенту считаем дублем. */
    public const DEDUPE_HOURS = 24;

    protected $fillable = [
        'user_id',
        'template_id',
        'channel',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** Была ли недавняя (в окне DEDUPE_HOURS) попытка по этому студенту. */
    public function scopeRecentForUser(Builder $query, int $userId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('sent_at', '>=', now()->subHours(self::DEDUPE_HOURS));
    }

    public static function sentRecentlyTo(int $userId): bool
    {
        return static::query()->recentForUser($userId)->exists();
    }
}
