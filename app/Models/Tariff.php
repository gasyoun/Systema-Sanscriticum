<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tariff extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'type',
        'block_number',
        'course_block_id',
        'price',
        'old_price',
        'description',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'is_active' => 'boolean',
        'block_number' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(CourseBlock::class, 'course_block_id');
    }

    /**
     * ==========================================
     * УМНЫЙ АПГРЕЙД: Расчет цены для конкретного студента
     * ==========================================
     */
    public function getDiscountPercentForUser($user): int
    {
        if (! $user) {
            return 0;
        }

        // «Прана сгорает»: для неблагонадёжных студентов loyalty-скидка
        // отключается до тех пор, пока менеджер не снимет флаг вручную.
        if ($user instanceof \App\Models\User && $user->isUnreliable()) {
            return 0;
        }

        // cached() — кэширует синглтон в Cache::rememberForever c авто-сбросом
        // на saved/deleted. На странице «Должники» этот метод дёргается сотни раз
        // (по одному вызову на каждый блок каждой пары user×курс), и без кэша
        // мы бы делали столько же SELECT'ов по marketing_settings.
        $marketing = \App\Models\MarketingSetting::cached();
        if (! $marketing || ! $marketing->is_loyalty_active) {
            return 0;
        }

        // ЖЕЛЕЗОБЕТОННЫЙ ПОДСЧЕТ УНИКАЛЬНЫХ КУРСОВ (pluck + unique)
        // Депозит (бронь) не считается «купленным курсом» для лояльности —
        // иначе бронь курса фиктивно увеличивала бы скидку.
        $paidCoursesCount = \App\Models\Payment::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'success'])
            ->where('tariff', '!=', 'deposit')
            ->where('created_at', '>=', now()->subYear()) // За последний год
            ->whereNotNull('course_id') // Исключаем системные платежи без курса
            ->pluck('course_id')
            ->unique()
            ->count();

        // Проверяем пороги (от большего к меньшему)
        if ($marketing->wholesale_large_threshold > 0 && $paidCoursesCount >= $marketing->wholesale_large_threshold) {
            return $marketing->wholesale_large_discount;
        } elseif ($marketing->wholesale_small_threshold > 0 && $paidCoursesCount >= $marketing->wholesale_small_threshold) {
            return $marketing->wholesale_small_discount;
        }

        return 0; // Нет скидки
    }

    /**
     * Расчет итоговой цены (использует процент из метода выше)
     */
    /**
     * Расчет итоговой цены для пользователя.
     *
     * Учитывает:
     *  - скидку лояльности / накопительную (оптовики) — через getDiscountPercentForUser()
     *
     * НЕ учитывает (отключено через config('features.upgrade_payments_enabled')):
     *  - "апгрейд" — вычитание сумм ранее оплаченных блоков при покупке полного курса.
     *    Логика временно отключена: вызывала пересчёт в минус при повторных покупках
     *    'full' и расхождение между витриной/чекаутом/эквайрингом.
     */
    public function calculateFinalPriceForUser($user): float
    {
        if (! $user) {
            return (float) $this->price;
        }

        $finalPrice = (float) $this->price;

        // 1. Скидка лояльности / накопительная (оптовики) — остаётся включённой
        $discountPercent = $this->getDiscountPercentForUser($user);
        if ($discountPercent > 0) {
            $finalPrice -= $finalPrice * ($discountPercent / 100);
        }

        // 2. Зачёт неизрасходованных депозитов (бронь курса). Изолированная логика,
        //    НЕ под флагом upgrade_payments_enabled — старый upgrade-механизм
        //    ниже не трогаем. max(0, ...) в конце страхует от отрицательной цены,
        //    если депозит превышает стоимость блока.
        if ($this->course_id) {
            $depositCredit = \App\Models\Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $this->course_id)
                ->unconsumedDeposits()
                ->sum('amount');

            $finalPrice -= (float) $depositCredit;
        }

        // 3. АПГРЕЙД (доплата с учётом ранее купленных блоков) — управляется фича-флагом
        if (config('features.upgrade_payments_enabled', false)
            && $this->course_id
            && $this->type === 'full'
        ) {
            // ВНИМАНИЕ: текущая реализация некорректна — вычитает ВСЕ платежи по курсу,
            // включая прошлые 'full'. Перед включением переписать на учёт только 'block_*'
            // и реальной стоимости блока на момент перерасчёта.
            $alreadyPaidAmount = \App\Models\Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $this->course_id)
                ->whereIn('status', ['paid', 'success'])
                ->where('tariff', 'like', 'block_%') // защита: только блоки
                ->sum('amount');

            $finalPrice -= (float) $alreadyPaidAmount;
        }

        return max(0, $finalPrice);
    }

    /**
     * Куплен ли этот конкретный тариф пользователем.
     * Для full-тарифа — есть ли успешный платёж с tariff='full' на этом курсе.
     * Для block-тарифа — есть ли платёж с ключом 'block_{block_number}'.
     */
    public function isPurchasedBy($user): bool
    {
        if (! $user || ! $this->course_id) {
            return false;
        }

        $tariffKey = $this->type === 'block'
            ? 'block_'.$this->block_number
            : 'full';

        return \App\Models\Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->where('tariff', $tariffKey)
            ->whereIn('status', ['paid', 'success'])
            ->exists();
    }
}
