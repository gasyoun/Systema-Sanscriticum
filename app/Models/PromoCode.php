<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'course_id',
        'usage_limit',
        'used_count',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Курс, к которому привязан код. NULL = код общий.
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // Применим ли код к данному курсу: общий код (course_id = null) — всегда,
    // иначе курс тарифа должен совпасть с привязанным курсом.
    public function appliesToCourse(?int $courseId): bool
    {
        return $this->course_id === null || (int) $this->course_id === (int) $courseId;
    }

    // Окно, в течение которого незавершённый (pending) платёж «держит» промокод
    // за пользователем — только для авторитетной проверки в момент оплаты
    // (hasRecentPendingForUser). Защищает от гонки в двух вкладках, но не
    // блокирует навсегда брошенную оплату (по истечении окна код снова доступен).
    private const PENDING_HOLD_MINUTES = 30;

    // Уже погашен этим пользователем? Правило «один человек — один раз»:
    // код израсходован, когда у юзера есть ОПЛАЧЕННЫЙ платёж с этим кодом
    // (зеркалит PaymentObserver::SUCCESS_STATUSES). Брошенный/pending чекаут НЕ
    // сжигает код (advisory-проверка в CheckoutController::applyPromo). Гость
    // (null) — проверить нельзя, авторитетная проверка делается в PaymentController.
    public function redeemedByUser(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return Payment::where('promo_code_id', $this->id)
            ->where('user_id', $userId)
            ->paid()
            ->exists();
    }

    // Есть ли у пользователя СВЕЖИЙ pending с этим кодом. Используется только в
    // авторитетном createPayment (под lockForUpdate на строке кода), чтобы два
    // одновременных чекаута в разных вкладках не применили одноразовый код дважды:
    // redeemedByUser видит лишь paid, поэтому в гонке оба pending прошли бы её.
    public function hasRecentPendingForUser(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return Payment::where('promo_code_id', $this->id)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(self::PENDING_HOLD_MINUTES))
            ->exists();
    }

    // Атомарно засчитывает использование кода при подтверждённой оплате.
    // Вызывается из Payment при переходе платежа в paid (а не при создании
    // pending), чтобы брошенные чекауты не исчерпывали usage_limit.
    public function markRedeemed(): void
    {
        $this->increment('used_count');
    }

    // Метод: Проверяет, можно ли применить этот код прямо сейчас
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Проверка на количество использований
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        // Проверка на срок действия
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    // Метод: Считает итоговую цену с учетом этого промокода
    public function calculateDiscountedPrice(float $originalPrice): float
    {
        if ($this->type === 'percent') {
            $discount = $originalPrice * ($this->value / 100);
            $finalPrice = $originalPrice - $discount;
        } else {
            // Если скидка фиксированная (например, 1000 руб)
            $finalPrice = $originalPrice - $this->value;
        }

        // Цена не может уйти в минус
        return $finalPrice > 0 ? $finalPrice : 0;
    }
}
