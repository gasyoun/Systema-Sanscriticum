<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Hard-bounce / unsubscribe suppression list (H1449 A2). An address here is
 * never sent to again — enforced globally by
 * App\Listeners\Email\EnforceMailSendingGuards, not per-Mailable.
 */
class SuppressedEmail extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'reason',
        'suppressed_at',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
    ];

    public static function suppress(string $email, string $reason): self
    {
        return static::query()->updateOrCreate(
            ['email' => mb_strtolower($email)],
            ['reason' => $reason, 'suppressed_at' => now()],
        );
    }

    public static function isSuppressed(string $email): bool
    {
        return static::query()->where('email', mb_strtolower($email))->exists();
    }
}
