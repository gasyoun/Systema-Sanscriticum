<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Партнёр (агент) B2B-программы: внешний человек, который рекомендует наши
 * курсы и получает фиксированную выплату за каждого приведённого клиента.
 * Отдельная сущность от студента (User): партнёр может вообще не быть нашим
 * студентом. Привязка приведённых клиентов — через users.partner_id, аудит
 * начислений и выплат — через partner_conversions.
 */
class Partner extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_PENDING => 'На модерации',
        self::STATUS_ACTIVE => 'Активен',
        self::STATUS_SUSPENDED => 'Приостановлен',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'telegram_username',
        'code',
        'status',
        'payout_method',
        'payout_details',
        'user_id',
        'reward_amount_override',
        'reward_percent',
        'notes',
        'approved_at',
    ];

    protected $casts = [
        'reward_amount_override' => 'decimal:2',
        'reward_percent' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    /** Начисления/выплаты по приведённым клиентам. */
    public function conversions(): HasMany
    {
        return $this->hasMany(PartnerConversion::class);
    }

    /** Приведённые клиенты (аккаунты студентов, привязанные по partner_id). */
    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'partner_id');
    }

    /** Собственный аккаунт партнёра, если он также наш студент (не обязателен). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Эффективная ставка вознаграждения за одного клиента: индивидуальная
     * (reward_amount_override), иначе — глобальная из config/partner.php.
     */
    public function rewardAmount(): float
    {
        if (filled($this->reward_amount_override) && (float) $this->reward_amount_override > 0) {
            return (float) $this->reward_amount_override;
        }

        return (float) config('partner.reward_amount', 1000);
    }

    /**
     * Ратифицированная MG 23-08 схема А: процент первого платежа приведённого
     * ученика. Приоритет: reward_percent > reward_amount_override > глобальный
     * дефолт. Процент считается от фактической суммы qualifying-платежа.
     */
    public function rewardOnPayment(Payment $payment): float
    {
        if (filled($this->reward_percent) && (float) $this->reward_percent > 0) {
            return round(((float) $this->reward_percent / 100) * (float) $payment->amount, 2);
        }

        return $this->rewardAmount();
    }

    /** Публичная партнёрская ссылка: last-touch атрибуция клиента по коду. */
    public function referralLink(): string
    {
        return url('/mitram/'.$this->code);
    }

    /**
     * Сумма к выплате: начисленные, но ещё не выплаченные вознаграждения.
     */
    public function amountOwed(): float
    {
        return (float) $this->conversions()
            ->where('status', PartnerConversion::STATUS_ACCRUED)
            ->sum('reward_amount');
    }

    /** Всего выплачено партнёру за всё время. */
    public function amountPaidOut(): float
    {
        return (float) $this->conversions()
            ->where('status', PartnerConversion::STATUS_PAID_OUT)
            ->sum('reward_amount');
    }

    /**
     * Сгенерировать уникальный партнёрский код. Префикс P отличает партнёрские
     * коды от студенческих реферальных (те — просто 8 символов).
     */
    public static function generateCode(): string
    {
        do {
            $code = 'P'.strtoupper(Str::random(7));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
