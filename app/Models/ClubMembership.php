<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Один оплаченный период клубного членства (H2644).
 *
 * Активность определяется не статусом, а датами — как в LessonAccessGrant:
 * `revoked_at IS NULL` И `grace_until > now()`. Отдельного поля «активен» нет
 * намеренно: денормализованный флаг рассинхронизируется с часами, а даты — нет.
 *
 * `ends_at` — конец ОПЛАЧЕННОГО периода, эту дату показываем студенту.
 * `grace_until` — момент, когда демон вправе снять право. Разница между ними
 * существует ровно для одного случая: продлившийся в последний день не должен
 * потерять доступ, пока вебхук банка догоняет демона.
 */
class ClubMembership extends Model
{
    use HasFactory;

    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_REHEARSAL = 'rehearsal';

    /** H3643 guest /register — Free-tier, no payment row. */
    public const SOURCE_GUEST_REGISTER = 'guest_register';

    public const REVOKE_LAPSED = 'lapsed';

    public const REVOKE_SUPERSEDED = 'superseded';

    protected $fillable = [
        'user_id',
        'payment_id',
        'tier_code',
        'term_months',
        'starts_at',
        'ends_at',
        'grace_until',
        'grace_days',
        'renewal_cancelled_at',
        'revoked_at',
        'revoke_reason',
        'source',
    ];

    protected $casts = [
        'term_months' => 'integer',
        'tier_code' => MembershipTier::class,
        'grace_days' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'grace_until' => 'datetime',
        'renewal_cancelled_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Право действует: не снято вручную и грейс ещё не вышел. */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->grace_until !== null
            && $this->grace_until->isFuture();
    }

    /** Продление отключено студентом («не продлевать»). */
    public function renewalCancelled(): bool
    {
        return $this->renewal_cancelled_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('grace_until', '>', now());
    }

    /**
     * Периоды, которые демон обязан снять: грейс вышел, право ещё не снято.
     * Строгое `<` — «никогда не раньше срока» держится на этом сравнении.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('grace_until', '<', now());
    }

    /**
     * Конец самого позднего активного периода пользователя — «оплачено до».
     * Периоды складываются встык (см. ClubMembershipService::syncFromPayment),
     * поэтому последний по ends_at и есть настоящая дата окончания.
     */
    public static function paidUntilFor(User $user): ?Carbon
    {
        $max = static::query()
            ->where('user_id', $user->id)
            ->active()
            ->max('ends_at');

        return $max ? Carbon::parse($max) : null;
    }
}
