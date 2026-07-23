<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * H1449 W1b — one recipient row per campaign; the "статус рассылки" ledger.
 * `pixel_token` is the opaque token both tracking endpoints resolve against
 * (never the user_id/email — org privacy rule: no PII in tracking URLs).
 */
class CampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'user_id',
        'email',
        'pixel_token',
        'sent_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
        'resend_of_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $recipient): void {
            if (! $recipient->pixel_token) {
                $recipient->pixel_token = (string) Str::uuid();
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resendOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resend_of_id');
    }

    /** Idempotent — a second open does not re-stamp. */
    public function markOpened(): void
    {
        if ($this->opened_at === null) {
            $this->updateQuietly(['opened_at' => now()]);
        }
    }

    public function markClicked(): void
    {
        if ($this->clicked_at === null) {
            $this->updateQuietly(['clicked_at' => now()]);
        }
    }
}
