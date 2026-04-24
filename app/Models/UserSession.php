<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'started_at',
        'last_heartbeat_at',
        'ended_at',
        'duration_seconds',
        'ip_address',
        'user_agent',
        'device_type',
        'browser',
        'os',
        'pages_viewed',
        'lessons_viewed',
        'is_active',
    ];

    protected $casts = [
        'started_at'        => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'ended_at'          => 'datetime',
        'duration_seconds'  => 'integer',
        'pages_viewed'      => 'integer',
        'lessons_viewed'    => 'integer',
        'is_active'         => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ActivityEvent::class, 'session_id');
    }

    /**
     * Закрыть сессию: выставить ended_at и пересчитать duration.
     * Используется при logout и в cron CloseStaleSessionsJob.
     */
    public function close(?\DateTimeInterface $endedAt = null): void
    {
        $endedAt ??= $this->last_heartbeat_at ?? now();

        $this->update([
            'ended_at'         => $endedAt,
            'duration_seconds' => max(0, $endedAt->getTimestamp() - $this->started_at->getTimestamp()),
            'is_active'        => false,
        ]);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}