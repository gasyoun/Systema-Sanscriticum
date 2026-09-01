<?php

namespace App\Models;

use App\Enums\MembershipTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Tariff extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'type',
        'block_number',
        'block_half',
        'start_block',
        'end_block',
        'course_block_id',
        'price',
        'old_price',
        'description',
        'is_active',
        'is_recording',
        // Клубное членство (H2644): срок тарифа в месяцах. NULL у всех обычных
        // тарифов — колонка аддитивна и на них не влияет.
        'membership_months',
        'membership_tier',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_recording' => 'boolean',
        'membership_months' => 'integer',
        'membership_tier' => MembershipTier::class,
        'block_number' => 'integer',
        'block_half' => 'integer', // NULL = весь блок; 1/2 = половина блока
        'start_block' => 'integer',
        'end_block' => 'integer',
    ];

    /**
     * Ключ доступа, записываемый в payments.tariff и сверяемый с Lesson::unlockingKeys().
     *  - не-блочный тариф            → 'full'
     *  - bundle с диапазоном блоков  → 'block_{start}' (остальные блоки диапазона
     *    открывает BlockAccessMaterializer по payments.start_block..end_block);
     *  - весь блок (block_half=null) → 'block_N'
     *  - половина блока              → 'block_N_hH'
     *
     * Bundle БЕЗ диапазона (мульти-курсовой пакет без привязки к блокам) остаётся
     * 'full' — как и раньше. «Один ключ = один блок» не нарушается: диапазон живёт
     * в числовых колонках платежа, а не в ключе.
     *
     * КЛУБНОЕ ЧЛЕНСТВО (H2644) — ключ `club_{N}m`, где N = membership_months.
     * Зачем отдельный ключ: три клубных тарифа (месяц/квартал/год) — не блочные,
     * поэтому у всех троих accessKey() вернул бы 'full', а payments не хранит
     * tariff_id — и срок оплаченного периода стало бы неоткуда взять. Ключ
     * `club_{N}m` делает платёж самоописывающим, читаемым прямо в БД.
     *
     * Ветка срабатывает ТОЛЬКО при заполненном membership_months, а он NULL у
     * 100 % существующих тарифов — поведение живого контура не меняется. Ключ
     * намеренно не совпадает ни с одним Lesson::unlockingKeys() ('full'/'block_N'):
     * клуб открывает каталог через ClubEntitlement, а не уроки курса-членства.
     */
    public function accessKey(): string
    {
        if ($this->membership_months !== null && (int) $this->membership_months > 0) {
            if ($this->membership_tier instanceof MembershipTier) {
                return 'membership_'.$this->membership_tier->value.'_'.(int) $this->membership_months.'m';
            }

            // Legacy H2644 tariff. It remains readable while tier rollout is dark;
            // membership:classify-tiers assigns the explicit code before activation.
            return 'club_'.(int) $this->membership_months.'m';
        }

        if ($this->type === 'bundle' && $this->start_block !== null) {
            return 'block_'.$this->start_block;
        }

        if ($this->type !== 'block') {
            return 'full';
        }

        return $this->block_half
            ? 'block_'.$this->block_number.'_h'.$this->block_half
            : 'block_'.$this->block_number;
    }

    public function expectedMembershipPrice(): ?int
    {
        if (! $this->membership_tier instanceof MembershipTier || $this->membership_months === null) {
            return null;
        }

        return $this->membership_tier->priceForTerm((int) $this->membership_months);
    }

    public function hasExpectedMembershipPrice(): bool
    {
        $expected = $this->expectedMembershipPrice();

        return $expected !== null && abs((float) $this->price - $expected) < 0.01;
    }

    /**
     * Продаёт ли этот тариф ЗАПИСЬ завершённого курса (evergreen), а не участие в
     * живом потоке. Маркер витрины/лейбла — НЕ отдельная система доступа: accessKey()
     * остаётся 'full'/'block_N', поэтому запись открывает те же уроки через тот же
     * PaymentObserver::grantAccess(). У завершённого курса живых занятий/Zoom-цикла
     * уже нет, поэтому «запись открывает уроки, но не расписание» выполняется само.
     */
    public function isRecording(): bool
    {
        return (bool) $this->is_recording;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(CourseBlock::class, 'course_block_id');
    }

    /** H3821 — published fixed EUR/USD PayPal prices, refreshed monthly. */
    public function foreignPrices(): HasMany
    {
        return $this->hasMany(TariffForeignPrice::class);
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
        if ($user instanceof User && $user->isUnreliable()) {
            return 0;
        }

        // cached() — кэширует синглтон в Cache::rememberForever c авто-сбросом
        // на saved/deleted. На странице «Должники» этот метод дёргается сотни раз
        // (по одному вызову на каждый блок каждой пары user×курс), и без кэша
        // мы бы делали столько же SELECT'ов по marketing_settings.
        $marketing = MarketingSetting::cached();
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
        $paidCoursesCount = Payment::where('user_id', $user->id)
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
            ? StudentDiscount::activeFor($user->id, $this->course_id, $this->block_number)
            : null;

        // Fixed-скидка: осмысленны только рубли (капаем по цене), процент не выводим.
        if ($individual && $individual->type === StudentDiscount::TYPE_FIXED) {
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
    public function calculateFinalPriceForUser($user): float
    {
        if (! $user) {
            return (float) $this->price;
        }

        // 1. Скидка (персональная/лояльность).
        $finalPrice = $this->priceAfterDiscountForUser($user);

        // 2. ЗАЧЁТ ПРИ ДОКУПКЕ: вычитаем уже оплаченное за то, что этот тариф «содержит».
        $finalPrice -= $this->upgradeCreditForUser($user);

        // 3. Зачёт неизрасходованной предоплаты (бронь курса / пробное занятие) —
        //    только в размере, реально нужном для покрытия остатка цены: излишек
        //    депозита не сгорает, а остаётся на следующую покупку (H071 #10).
        $finalPrice -= $this->prepaidCreditAppliedForUser($user);

        return max(0, $finalPrice);
    }

    /**
     * Цена после скидки (персональная скидка студента ИМЕЕТ ПРИОРИТЕТ и
     * применяется ВМЕСТО накопительной лояльности — не суммируются), ДО зачёта
     * предоплаты и докупки.
     */
    public function priceAfterDiscountForUser($user): float
    {
        $finalPrice = (float) $this->price;

        if (! $user) {
            return $finalPrice;
        }

        $individual = $this->course_id
            ? StudentDiscount::activeFor($user->id, $this->course_id, $this->block_number)
            : null;

        if ($individual) {
            $finalPrice = $individual->apply($finalPrice);
        } else {
            $discountPercent = $this->getDiscountPercentForUser($user);
            if ($discountPercent > 0) {
                $finalPrice -= $finalPrice * ($discountPercent / 100);
            }
        }

        return $finalPrice;
    }

    /**
     * Какая часть доступной предоплаты (депозит/пробное) БУДЕТ зачтена при
     * покупке этого тарифа: min(доступно, сколько осталось покрыть после скидки
     * и зачёта докупки). Ровно эта сумма пишется в payments.deposit_credit_applied
     * при создании заказа и ровно она гасится из депозитов при его оплате.
     */
    public function prepaidCreditAppliedForUser($user): float
    {
        if (! $user) {
            return 0.0;
        }

        $available = $this->prepaidCreditForUser($user);
        if ($available <= 0) {
            return 0.0;
        }

        $need = max(0.0, $this->priceAfterDiscountForUser($user) - $this->upgradeCreditForUser($user));

        return min($available, $need);
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

        // Стоимость содержащегося тарифа = net-кэш (amount) + зачтённая в него
        // предоплата (deposit_credit_applied): раньше депозитная часть половины
        // терялась при докупке целого блока, и студент переплачивал ровно на
        // сумму депозита (money-core, H071 #9). Условие amount>0 расширено на
        // «или была зачтена предоплата» — половина, полностью покрытая депозитом
        // (amount=0), тоже даёт зачёт; нулевые access-only siblings и conditional
        // по-прежнему отсекаются (у них deposit_credit_applied пуст + ->real()).
        $query = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->paid()
            ->real()
            ->where(function ($q) {
                $q->where('amount', '>', 0)
                    ->orWhere('deposit_credit_applied', '>', 0);
            });

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

        $credit = (float) $query->sum(DB::raw('amount + COALESCE(deposit_credit_applied, 0)'));
        $refunds = $this->upgradeRefundsForUser($user);

        return max(0.0, $credit + $refunds);
    }

    private function upgradeRefundsForUser($user): float
    {
        $query = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->paid()
            ->real()
            ->where('tariff', 'Расход')
            ->where('amount', '<', 0);

        if ($this->type === 'block') {
            $block = (int) $this->block_number;

            // Диапазонная атрибуция: возврат покрывает блок своим start..end.
            $range = function ($q) use ($block) {
                $q->whereNotNull('start_block')
                    ->where('start_block', '<=', $block)
                    ->where(function ($e) use ($block) {
                        $e->whereNull('end_block')
                            ->orWhere('end_block', '>=', $block);
                    });
            };

            if (config('features.upgrade_credit_refund_link')) {
                // Расход-возврат из админ-формы создаётся БЕЗ диапазона (форма
                // обнуляет start/end при выборе «Расход»), поэтому диапазонная
                // ветка его не видит и зачёт докупки не уменьшается — школа
                // теряла сумму возврата второй раз (H1405 C2). За флагом
                // добавляем атрибуцию по ссылке «Возврат за платёж №…»:
                // возврат, привязанный к ОПЛАЧЕННОЙ половине этого блока,
                // уменьшает зачёт. Возврат без диапазона и без ссылки остаётся
                // невидимым — операционное правило: заполнять ссылку всегда
                // (runbook §11.4 мануала money-access-core).
                $halves = ['block_'.$block.'_h1', 'block_'.$block.'_h2'];

                $query->where(function ($q) use ($range, $halves) {
                    $q->where($range)
                        ->orWhereHas('refundOf', function ($original) use ($halves) {
                            $original->whereIn('tariff', $halves)
                                ->paid()
                                ->real();
                        });
                });
            } else {
                $query->where($range);
            }
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

        // Остаток = amount − consumed_amount: частично зачтённый депозит
        // (deposit_consumed_at ещё null) отдаёт в кредит только непотраченное.
        return (float) Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->unconsumedDeposits()
            ->sum(DB::raw('amount - COALESCE(consumed_amount, 0)'));
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

        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->where('tariff', $this->accessKey())
            ->paid()
            ->exists();
    }
}
