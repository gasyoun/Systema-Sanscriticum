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
        'block_half',
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
        'block_half' => 'integer', // NULL = весь блок; 1/2 = половина блока
    ];

    /**
     * Ключ доступа, записываемый в payments.tariff и сверяемый с Lesson::unlockingKeys().
     *  - не-блочный тариф          → 'full'
     *  - весь блок (block_half=null) → 'block_N'
     *  - половина блока             → 'block_N_hH'
     */
    public function accessKey(): string
    {
        if ($this->type !== 'block') {
            return 'full';
        }

        return $this->block_half
            ? 'block_'.$this->block_number.'_h'.$this->block_half
            : 'block_'.$this->block_number;
    }

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
        // Депозит (бронь) и пробное занятие не считаются «купленным курсом» для
        // лояльности — иначе они фиктивно увеличивали бы скидку.
        $paidCoursesCount = \App\Models\Payment::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'success'])
            ->whereNotIn('tariff', ['deposit', 'trial'])
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
     * Итоговый процент скидки для подписи в UI. Персональная скидка студента
     * (StudentDiscount) имеет приоритет над лояльностью — так же, как в
     * calculateFinalPriceForUser. Для fixed-скидки считаем эквивалентный
     * процент от цены тарифа. Не учитывает зачёты депозита/докупки — это не
     * «скидка», а кредит.
     */
    public function effectiveDiscountPercentForUser($user): int
    {
        if (! $user) {
            return 0;
        }

        $individual = $this->course_id
            ? \App\Models\StudentDiscount::activeFor($user->id, $this->course_id, $this->block_number)
            : null;

        if ($individual) {
            if ($individual->type === \App\Models\StudentDiscount::TYPE_PERCENT) {
                return (int) round((float) $individual->value);
            }

            // fixed → доля от цены
            return (float) $this->price > 0
                ? (int) round((float) $individual->value / (float) $this->price * 100)
                : 0;
        }

        return $this->getDiscountPercentForUser($user);
    }

    /**
     * Расчет итоговой цены для пользователя.
     *
     * Учитывает:
     *  - персональную скидку студента на курс ИЛИ скидку лояльности (не суммируются);
     *  - зачёт неизрасходованных депозитов (бронь курса);
     *  - зачёт при докупке: сумма уже оплаченного за то, что этот тариф «содержит»
     *    (половины блока → при покупке целого блока; блоки → при покупке full).
     */
    public function calculateFinalPriceForUser($user): float
    {
        if (! $user) {
            return (float) $this->price;
        }

        $finalPrice = (float) $this->price;

        // 1. Скидка. Персональная скидка студента на этот курс ИМЕЕТ ПРИОРИТЕТ и
        //    применяется ВМЕСТО накопительной лояльности (не суммируется).
        $individual = $this->course_id
            ? \App\Models\StudentDiscount::activeFor($user->id, $this->course_id, $this->block_number)
            : null;

        if ($individual) {
            $finalPrice = $individual->apply($finalPrice);
        } else {
            $discountPercent = $this->getDiscountPercentForUser($user);
            if ($discountPercent > 0) {
                $finalPrice -= $finalPrice * ($discountPercent / 100);
            }
        }

        // 2. Зачёт неизрасходованных депозитов (бронь курса). max(0, ...) в конце
        //    страхует от отрицательной цены, если депозит превышает стоимость блока.
        if ($this->course_id) {
            $depositCredit = \App\Models\Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $this->course_id)
                ->unconsumedDeposits()
                ->sum('amount');

            $finalPrice -= (float) $depositCredit;
        }

        // 3. ЗАЧЁТ ПРИ ДОКУПКЕ: вычитаем уже оплаченное за то, что этот тариф «содержит».
        $finalPrice -= $this->upgradeCreditForUser($user);

        return max(0, $finalPrice);
    }

    /**
     * Зачёт при докупке: сумма уже оплаченного за тарифы, которые ПОЛНОСТЬЮ входят
     * в покупаемый сейчас (строгое вложение, без самого себя), по тому же курсу.
     *
     *   full        ← все 'block_%' курса (целые блоки и половины);
     *   block_N     ← 'block_N_h1', 'block_N_h2' (половины этого блока);
     *   block_N_hH  ← ничего (половины не пересекаются).
     *
     * Так купивший половину при покупке целого блока платит цена_блока − уплаченное за
     * половину, а две половины в сумме дают полную стоимость блока (зачёта между ними нет).
     */
    public function upgradeCreditForUser($user): float
    {
        if (! $user || ! $this->course_id || $this->type === 'vip' || $this->type === 'bundle') {
            return 0.0;
        }

        $query = \App\Models\Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->whereIn('status', ['paid', 'success']);

        if ($this->type === 'block' && $this->block_half) {
            // Половина блока ничего не содержит — зачёта нет.
            return 0.0;
        }

        if ($this->type === 'block') {
            // Целый блок содержит свои половины.
            $query->whereIn('tariff', [
                'block_'.$this->block_number.'_h1',
                'block_'.$this->block_number.'_h2',
            ]);
        } else {
            // 'full' содержит все блоки и половины курса. Зачёт уже оплаченных
            // блоков в стоимость полного курса — за фича-флагом (пока выключен,
            // включить, когда созреем). Половина→целый блок (выше) не зависит от него.
            if (! config('features.full_course_block_credit', false)) {
                return 0.0;
            }

            $query->where('tariff', 'like', 'block_%');
        }

        return (float) $query->sum('amount');
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

        return \App\Models\Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->where('tariff', $this->accessKey())
            ->whereIn('status', ['paid', 'success'])
            ->exists();
    }
}
