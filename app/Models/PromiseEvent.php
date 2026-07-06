<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Событие жизненного цикла обещания оплаты: истечение (демон), нудж студенту,
 * прощение долга куратором. Append-only — вручную не правится.
 */
class PromiseEvent extends Model
{
    use HasFactory;

    public const EVENT_EXPIRED = 'expired';

    public const EVENT_NUDGED = 'nudged';

    public const EVENT_WAIVED = 'waived';

    /** Только момент записи; лог неизменяем. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_promise_id',
        'user_id',
        'course_id',
        'amount',
        'event',
        'actor_id',
        'actor_name',
        'note',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function promise(): BelongsTo
    {
        return $this->belongsTo(PaymentPromise::class, 'payment_promise_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Записать событие по обещанию. actor берётся из auth (null = система/демон).
     */
    public static function log(PaymentPromise $promise, string $event, ?string $note = null): self
    {
        $actor = auth()->user();

        return self::create([
            'payment_promise_id' => $promise->getKey(),
            'user_id' => $promise->user_id,
            'course_id' => $promise->course_id,
            'amount' => $promise->amount,
            'event' => $event,
            'actor_id' => $actor instanceof User ? $actor->getKey() : null,
            'actor_name' => $actor instanceof User ? $actor->name : 'Система',
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
