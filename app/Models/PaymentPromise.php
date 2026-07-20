<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CuratorNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPromise extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'course_id',
        'promised_at',
        'actual_paid_at',
        'amount',
        'status',
        'note',
        'fulfilled_at',
        'cancelled_at',
        'fulfilled_payment_id',
        'installment_group_id',
        'revocation_channels_sent',
        'revocation_notified_at',
        'student_rescheduled_at',
    ];

    protected $casts = [
        'promised_at' => 'date',
        'actual_paid_at' => 'date',
        'student_rescheduled_at' => 'datetime',
        'amount' => 'decimal:2',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'revocation_channels_sent' => 'array',
        'revocation_notified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Уведомляем кураторов о новом одиночном обещании. Строки рассрочки
        // (installment_group_id != null) шлёт InstallmentPlanCreator одним
        // общим сообщением — здесь их пропускаем, чтобы не плодить N штук.
        static::created(function (self $promise): void {
            if ($promise->installment_group_id === null) {
                app(CuratorNotifier::class)->promiseCreated($promise);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function fulfilledPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'fulfilled_payment_id');
    }

    /** Активные обещания: дата ещё не прошла. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereDate('promised_at', '>=', now()->toDateString());
    }

    /** Просроченные обещания: status=active, но дата прошла. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereDate('promised_at', '<', now()->toDateString());
    }

    public function scopeForPair(Builder $query, int $userId, int $courseId): Builder
    {
        return $query->where('user_id', $userId)->where('course_id', $courseId);
    }

    public function scopeInGroup(Builder $query, string $groupId): Builder
    {
        return $query->where('installment_group_id', $groupId);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->promised_at !== null
            && $this->promised_at->lt(now()->startOfDay());
    }

    /**
     * Непогашенная договорённость: ещё ждём денег. Это активные обещания и
     * те, что демон `promises:expire` уже перевёл в expired (срок прошёл, но
     * долг никуда не делся).
     */
    public function isUnmet(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_EXPIRED], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    /**
     * Просрочено для целей отображения: либо уже помечено expired демоном,
     * либо ещё active, но обещанная дата прошла (окно до ближайшего прогона
     * `promises:expire`).
     */
    public function isOverdueOrExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED || $this->isOverdue();
    }

    public function isPartOfInstallment(): bool
    {
        return $this->installment_group_id !== null;
    }

    public function markActualPaid(\DateTimeInterface $date): void
    {
        $this->forceFill(['actual_paid_at' => $date])->save();
    }

    /** @return Collection<int, self> */
    public function installmentMates(): Collection
    {
        if ($this->installment_group_id === null) {
            return new Collection;
        }

        return self::query()
            ->inGroup($this->installment_group_id)
            ->orderBy('promised_at')
            ->get();
    }
}
