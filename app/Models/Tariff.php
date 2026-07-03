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
        // Депозит (бронь), пробное, расходы и выплаты ЗП не считаются «купленным
        // курсом» для лояльности — иначе они фиктивно увеличивали бы скидку.
        // real() + amount>0: conditional-доступ «под обещание» (amount=0,
        // is_conditional) и любые 0₽-заказы (100%-промо) — не покупка и НЕ должны
        // повышать оптовую скидку (см. ShopController/CourseCatalog, где такой же
        // ->real() уже стоит).
        $paidCoursesCount = \App\Models\Payment::where('user_id', $user->id)
            ->paid()
            ->real()
            ->where('amount', '>', 0)
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
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
     * Скидка (персональная или лояльность) для подписи в UI и пометки платежа.
     * Персональная скидка студента имеет приоритет над лояльностью — как в
     * calculateFinalPriceForUser. Учитывает ТОЛЬКО скидку, без зачётов
     * депозита/докупки (это кредит, а не скидка).
     *
     * Возвращает ['percent' => ?int, 'amount' => float, 'label' => string]:
     *  - percent-скидка/лояльность → percent + рублёвый эквивалент, label «-10%»;
     *  - fixed-скидка → только рубли (не больше цены), percent = null, label «-1000 ₽»;
     *  - нет скидки → percent null, amount 0, label ''.
     *
     * @return array{percent: ?int, amount: float, label: string}
     */
    public function discountInfoForUser($user): array
    {
        $none = ['percent' => null, 'amount' => 0.0, 'label' => ''];

        if (! $user) {
            return $none;
        }

        $price = (float) $this->price;

        $individual = $this->course_id
            ? \App\Models\StudentDiscount::activeFor($user->id, $this->course_id, $this->block_number)
            : null;

        // Fixed-скидка: осмысленны только рубли (капаем по цене), процент не выводим.
        if ($individual && $individual->type === \App\Models\StudentDiscount::TYPE_FIXED) {
            $amount = min((float) $individual->value, $price);

            return $amount > 0
                ? ['percent' => null, 'amount' => round($amount, 2), 'label' => '-'.number_format($amount, 0, '.', ' ').' ₽']
                : $none;
        }

        // Percent-скидка (персональная) или лояльность.
        $percent = $individual
            ? (int) round((float) $individual->value)
            : $this->getDiscountPercentForUser($user);

        if ($percent <= 0) {
            return $none;
        }

        return [
            'percent' => $percent,
            'amount' => round($price * $percent / 100, 2),
            'label' => '-'.$percent.'%',
        ];
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
    public function calculateFinalPriceForUser($user, bool $includePrepaid = true): float
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

        // 2. Зачёт неизрасходованной предоплаты (бронь курса / пробное занятие).
        //    max(0, ...) в конце страхует от отрицательной цены, если предоплата
        //    превышает стоимость блока.
        if ($includePrepaid) {
            $finalPrice -= $this->prepaidCreditForUser($user);
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
            ->paid()
            ->real()
            ->where('amount', '>', 0);

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

        $credit = (float) $query->sum('amount');
        $refunds = $this->upgradeRefundsForUser($user);

        return max(0.0, $credit + $refunds);
    }

    private function upgradeRefundsForUser($user): float
    {
        $query = \App\Models\Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->paid()
            ->real()
            ->where('tariff', 'Расход')
            ->where('amount', '<', 0);

        if ($this->type === 'block') {
            $block = (int) $this->block_number;

            $query->whereNotNull('start_block')
                ->where('start_block', '<=', $block)
                ->where(function ($q) use ($block) {
                    $q->whereNull('end_block')
                        ->orWhere('end_block', '>=', $block);
                });
        }

        return (float) $query->sum('amount');
    }

    /**
     * Зачёт неизрасходованной предоплаты по курсу: брони (deposit) и пробного
     * занятия (trial) — обе суммы засчитываются в стоимость тарифа. Единый
     * источник и для расчёта цены, и для подписи под ней.
     */
    public function prepaidCreditForUser($user): float
    {
        if (! $user || ! $this->course_id) {
            return 0.0;
        }

        return (float) \App\Models\Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->unconsumedDeposits()
            ->sum('amount');
    }

    /**
     * Подпись под итоговой ценой: объясняет, ПОЧЕМУ она ниже базовой. Источники
     * снижения: скидка (персональная/лояльность), зачёт предоплаты (бронь/пробное),
     * зачёт ранее оплаченного (докупка). Возвращает '' если цена не снижена —
     * чтобы не писать «с учётом скидки», когда никакой скидки на самом деле нет.
     */
    public function priceReductionNoteForUser($user): string
    {
        if (! $user) {
            return '';
        }

        $reasons = [];

        if ((float) $this->discountInfoForUser($user)['amount'] > 0) {
            $reasons[] = 'скидки';
        }
        if ($this->prepaidCreditForUser($user) > 0) {
            $reasons[] = 'предоплаты';
        }
        if ($this->upgradeCreditForUser($user) > 0) {
            $reasons[] = 'ранее оплаченного';
        }

        if (empty($reasons)) {
            return '';
        }

        return 'Стоимость с учётом '.$this->humanJoinRu($reasons);
    }

    /** Перечисление по-русски: «a», «a и b», «a, b и c». */
    private function humanJoinRu(array $items): string
    {
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' и '.$last;
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
            ->paid()
            ->exists();
    }
}
