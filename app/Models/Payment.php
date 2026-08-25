<?php

namespace App\Models;

use App\Jobs\SendTelegramMessageJob;
use App\Mail\CourseWelcomeMail;
use App\Mail\DepositReceivedMail;
use App\Mail\PurchaseConfirmationMail;
use App\Mail\StudentWelcomeMail;
use App\Mail\TrialZoomLinkMail;
use App\Models\Concerns\TracksBlame;
use App\Services\BlockAccessMaterializer;
use App\Services\CuratorNotifier;
use App\Services\GiftCertificateService;
use App\Services\GrammarLab\GrammarLabEntitlementService;
use App\Services\Membership\ClubMembershipService;
use App\Services\Messaging\DeliveryChannelManager;
use App\Services\Prana\PranaService;
use App\Services\PromiseAutoFulfiller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;
    use TracksBlame;

    protected $fillable = [
        'user_id',
        'lead_id',
        'course_id',
        'promo_code_id',
        'amount',
        // Справочная сумма в валюте (USD/EUR) — только для отчёта, в расчётах не участвует.
        'foreign_amount',
        'foreign_currency',
        'discount_percent',
        'discount_amount',
        'prana_spent',
        'referral_credit_applied',
        'tariff',
        'deposit_consumed_at',
        'consumed_amount',
        'deposit_credit_applied',
        'status',
        // Момент первого входа в оплаченный статус — резервная опора
        // resurrection-guard'а, не зависящая от payment_audits (H1645).
        // Стамповается один раз (fireOnPaid) и никогда не перезаписывается.
        'first_paid_at',
        // Возврат за конкретный платёж (H352): у платежа-«Расход» указывает
        // исходную оплату, чью выручку усекать по месяц возврата.
        'refund_of_payment_id',
        'transaction_id',
        'payment_link_expires_at',
        // --- Полу-интегрированная валютная оплата (PayPal-заявка студента) ---
        'provider',
        'payment_method',
        'proof_path',
        // --- НОВЫЕ ПОЛЯ: Для поблочной оплаты ---
        'start_block',
        'end_block',
        // Ручной override месяца начисления ЗП (YYYY-MM); null = авто по блокам.
        'salary_recognition_month',
        // --- Conditional access под обещание/рассрочку ---
        'is_conditional',
        'linked_promise_id',
        // Платёж создан должником самостоятельно из кабинета (self-service).
        'is_self_service',
        // --- Прямой платёж на личный счёт преподавателя (минуя кассу школы) ---
        'received_account',
        'received_by_teacher_id',
        'payer_note',
        // Structured claim payload (PayPal / company invoice) — see claim_meta JSON.
        'claim_meta',
        // Дата платежа задаётся вручную при создании из админки.
        'created_at',
    ];

    /** Деньги пришли в кассу школы (обычный платёж). */
    public const RECEIVED_SCHOOL = 'school';

    /** Деньги пришли напрямую на личный счёт преподавателя (минуя кассу школы). */
    public const RECEIVED_TEACHER = 'teacher_personal';

    /**
     * Платёжный источник: валютная заявка студента через PayPal (оплата из-за
     * рубежа), сверяется вручную. null = касса/Точка (дефолт). Отличает
     * pending-заявку PayPal от pending-платежа Точки в фильтрах админки.
     */
    public const PROVIDER_PAYPAL = 'paypal';

    /**
     * Auto-charge from PayPal Subscriptions API (H2027). Not a manual claim —
     * webhook-paid; must stay out of MANUAL_CLAIM_PROVIDERS. Distinct from
     * PROVIDER_PAYPAL so Filament «Заявки PayPal» does not mix subscription rows.
     */
    public const PROVIDER_PAYPAL_SUBSCRIPTION = 'paypal_subscription';

    /**
     * Счёт на оплату для юрлица / ИП (безнал по реквизитам). Pending до ручной
     * сверки поступления; доступ только после paid в Filament (H2017).
     */
    public const PROVIDER_INVOICE = 'invoice';

    /**
     * Заявка об оплате банковским переводом на внешний счёт получателя школы
     * за рубежом (H3497, SEPA/SWIFT в Австрию). Автосверки нет — pending до
     * ручного подтверждения в Filament; trusted-студентам paid сразу
     * (зеркало рулинга 22-08-2026 из PayPal-канала).
     */
    public const PROVIDER_BANK_SEPA = 'bank_sepa';

    /**
     * Providers that wait for human reconciliation and must never be reaped by
     * payments:expire-stale-checkouts (they are not abandoned bank links).
     *
     * @var list<string>
     */
    public const MANUAL_CLAIM_PROVIDERS = [
        self::PROVIDER_PAYPAL,
        self::PROVIDER_INVOICE,
        self::PROVIDER_BANK_SEPA,
    ];

    protected $casts = [
        'is_conditional' => 'boolean',
        'is_self_service' => 'boolean',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'foreign_amount' => 'decimal:2',
        'referral_credit_applied' => 'decimal:2',
        'first_paid_at' => 'datetime',
        'deposit_consumed_at' => 'datetime',
        'consumed_amount' => 'decimal:2',
        'deposit_credit_applied' => 'decimal:2',
        'payment_link_expires_at' => 'datetime',
        'claim_meta' => 'array',
        // Поблочная оплата: в БД nullable int, но без каста Eloquent отдаёт
        // строку и ломает strict-typed ?int в CuratorNotifier::blocksLabel().
        'start_block' => 'integer',
        'end_block' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function linkedPromise(): BelongsTo
    {
        return $this->belongsTo(PaymentPromise::class, 'linked_promise_id');
    }

    /** Исходный платёж, за который сделан этот возврат (H352). */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of_payment_id');
    }

    /** Возвраты, привязанные к этому платежу (H352). */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of_payment_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    // Аудит-таймлайн: кто/что/когда правил платёж (append-only, PaymentAuditObserver).
    public function audits(): HasMany
    {
        return $this->hasMany(PaymentAudit::class)->latest();
    }

    // Журнал доставок денежных вебхуков (H1359, append-only).
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class)->latest();
    }

    /**
     * Был ли платёж когда-либо в оплаченном статусе — по аудит-следу
     * (PaymentAuditObserver пишет и системные webhook-изменения). Ловит и
     * переход pending→paid (updated: status = [old, new]), и создание сразу
     * оплаченным (created: status — скаляр-снимок). В связке с «сейчас НЕ
     * оплачен» это и есть отменённый/возвращённый платёж, который повторный
     * success-вебхук не должен воскрешать (H1359, guard rejected_resurrection).
     *
     * ВНИМАНИЕ: у PaymentAudit колонка называется `changes`, что совпадает с
     * protected-свойством Eloquent Model::$changes (трекинг «грязных» полей).
     * Payment и PaymentAudit — сиблинги от Model, поэтому `$audit->changes`
     * ИЗ ЭТОГО класса читает пустое protected-свойство, а НЕ атрибут. Берём
     * значение только через getAttribute(), иначе связь не срабатывает.
     *
     * H1645: `first_paid_at` — прямая, независимая от аудита опора — проверяется
     * первой. Она стамповается write-путём (fireOnPaid + create-as-paid payloads)
     * и бэкфиллится командой `payments:backfill-first-paid-at`, так что покрывает
     * и «до-аудитную» эпоху (PaymentAuditObserver существует только с 08-06-2026),
     * и withoutEvents-пути создания уже-оплаченных платежей, невидимые аудиту.
     * Аудит-обход остаётся фолбэком, пока колонка не забэкфилена, и теперь
     * инспектирует ОБЕ стороны диффа ($status[0] и $status[1]) — было: только
     * новое значение, из-за чего запись paid→failed (новое = failed) была
     * невидима, хотя старое значение доказывает, что платёж был оплачен.
     */
    public function hasPriorPaidTransition(): bool
    {
        if ($this->first_paid_at !== null) {
            return true;
        }

        foreach ($this->audits()->get() as $audit) {
            $status = $audit->getAttribute('changes')['status'] ?? null;

            if (! is_array($status)) {
                if (in_array($status, self::PAID_STATUSES, true)) {
                    return true;
                }

                continue;
            }

            if (in_array($status[0] ?? null, self::PAID_STATUSES, true)
                || in_array($status[1] ?? null, self::PAID_STATUSES, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /** Преподаватель, получивший этот платёж напрямую на личный счёт (для teacher_personal). */
    public function receivedByTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'received_by_teacher_id');
    }

    /** Платёж пришёл напрямую на личный счёт преподавателя (не в кассу школы). */
    public function isTeacherReceived(): bool
    {
        return $this->received_account === self::RECEIVED_TEACHER;
    }

    public function isDeposit(): bool
    {
        return $this->tariff === 'deposit';
    }

    /** Покупка пробного занятия с витрины — открывает доступ к одному уроку (не к курсу). */
    public function isTrial(): bool
    {
        return $this->tariff === 'trial';
    }

    /** H440/H471 — оплата трека «с проверкой» ₽500 диагностического марафона. Доступа к курсу не даёт. */
    public function isMarathonPaid(): bool
    {
        return $this->tariff === 'marathon_paid';
    }

    /**
     * Добровольное пожертвование на деятельность Института (/mecenaty, план
     * института N2). Донорская рамка без встречного пакета благ: доступа,
     * групп, членства и лид-конверсии не несёт — только бухгалтерская строка.
     */
    public function isDonation(): bool
    {
        return $this->tariff === 'donation';
    }

    /** Заявка об оплате из-за рубежа (PayPal), поданная студентом. */
    public function isPaypal(): bool
    {
        return $this->provider === self::PROVIDER_PAYPAL;
    }

    /**
     * Покупка подарочного сертификата (H3334): деньги записаны, но доступ
     * покупателю НЕ открывается — вместо этого выпускается одноразовый код
     * активации получателю. Отдельный процессор в fireOnPaid (как deposit/
     * trial/marathon), чтобы штатный grantAccess покупателя в группы не добавлял.
     */
    public function isGiftCertificate(): bool
    {
        return $this->tariff === 'gift';
    }

    /** Счёт на оплату юрлицу / ИП (безнал), ожидает ручной сверки. */
    public function isCompanyInvoice(): bool
    {
        return $this->provider === self::PROVIDER_INVOICE;
    }

    /** Заявка об оплате банковским переводом (SEPA/SWIFT, H3497). */
    public function isBankSepa(): bool
    {
        return $this->provider === self::PROVIDER_BANK_SEPA;
    }

    /**
     * Банковская заявка существующего ученика с мгновенным доступом (зеркало
     * рулинга 22-08-2026) — кандидат выборочной сверки до verified_at.
     */
    public function isAutoTrustedBankClaim(): bool
    {
        return $this->isBankSepa() && (bool) $this->claimMeta('auto_trusted');
    }

    /**
     * Scalar from claim_meta JSON (PayPal / company invoice), or null.
     */
    public function claimMeta(string $key, mixed $default = null): mixed
    {
        $meta = $this->claim_meta;

        if (! is_array($meta) || ! array_key_exists($key, $meta)) {
            return $default;
        }

        return $meta[$key];
    }

    /**
     * PayPal-заявка существующего ученика, получившая доступ сразу (ruling
     * 22-08-2026), — кандидат выборочной сверки, пока verified_at не проставлен.
     */
    public function isAutoTrustedPaypal(): bool
    {
        return $this->isPaypal() && (bool) $this->claimMeta('auto_trusted');
    }

    /** Отметка «сверка пройдена» для авто-доверенной PayPal-заявки. */
    public function markPaypalVerified(): void
    {
        $meta = is_array($this->claim_meta) ? $this->claim_meta : [];
        $meta['verified_at'] = now()->toIso8601String();
        $this->update(['claim_meta' => $meta]);
    }

    /** Human invoice number for printable счёт (stable per payment id). */
    public function invoiceNumber(): string
    {
        return 'СЧ-'.$this->id;
    }

    /**
     * Системный расход / возврат — чисто бухгалтерская запись (часто с
     * отрицательной суммой). Не открывает доступ, не шлёт письма/уведомления.
     */
    public function isExpense(): bool
    {
        return $this->tariff === 'Расход';
    }

    /**
     * Выплата зарплаты преподавателю — бухгалтерская транзакция-отток
     * (отрицательная сумма) на реальном курсе. Зеркало TeacherPayout в
     * «Финансах». Не открывает доступ, не участвует в выручке/долгах/accrual.
     */
    public function isSalaryPayout(): bool
    {
        return $this->tariff === 'salary_payout';
    }

    /** Платёж прошёл со скидкой (персональной или лояльности). */
    public function hasDiscount(): bool
    {
        return (float) $this->discount_percent > 0 || (float) $this->discount_amount > 0;
    }

    /**
     * Короткая подпись скидки: «-10%» для процентной, «-1000 ₽» для фиксированной,
     * '' если скидки нет. Источник для бейджа в админке и выгрузки в Google Sheet.
     */
    public function discountLabel(): string
    {
        if ((float) $this->discount_percent > 0) {
            return '-'.(int) $this->discount_percent.'%';
        }

        if ((float) $this->discount_amount > 0) {
            return '-'.number_format((float) $this->discount_amount, 0, '.', ' ').' ₽';
        }

        return '';
    }

    /**
     * Подпись справочной валютной суммы для колонки «Примечание» отчёта,
     * например «50 $» / «45 €». Пусто, если сумма не задана. В расчётах не участвует.
     */
    public function foreignAmountLabel(): string
    {
        if ((float) $this->foreign_amount <= 0 || empty($this->foreign_currency)) {
            return '';
        }

        $symbol = match ($this->foreign_currency) {
            'USD' => '$',
            'EUR' => '€',
            default => $this->foreign_currency,
        };

        return number_format((float) $this->foreign_amount, 2, '.', ' ').' '.$symbol;
    }

    /**
     * Открывает ли эта оплата доступ к блоку $block, половине $half (1|2).
     * Зеркалит Lesson::unlockingKeys (full / весь блок / нужная половина) плюс
     * диапазон start_block..end_block (импорт/ручное заполнение). Используется
     * в отчёте «Участники по блокам» (CourseBlockParticipantsReport).
     */
    public function coversBlockHalf(int $block, int $half): bool
    {
        if (in_array($this->tariff, ['full', 'block_'.$block, 'block_'.$block.'_h'.$half], true)) {
            return true;
        }

        $start = (int) $this->start_block;
        if ($start > 0) {
            $end = (int) $this->end_block ?: $start;

            return $block >= $start && $block <= $end;
        }

        return false;
    }

    /**
     * Человекочитаемая пометка операции — единый источник для админки
     * (PaymentResource) и выгрузки в финансовую Google-таблицу
     * (SendPaymentToSheetJob). Расшифровывает сырой ключ tariff.
     */
    public function operationLabel(): string
    {
        return match (true) {
            $this->isDeposit() => '📌 Бронь курса (предоплата)',
            $this->isTrial() => '🎟 Пробное занятие',
            $this->isExpense() => '💸 Технический расход / возврат',
            $this->isSalaryPayout() => '👨‍🏫 Выплата преподавателю',
            $this->isGiftCertificate() => '🎁 Подарочный сертификат',
            $this->tariff === 'full' => 'Весь курс',
            default => $this->blockLabel(),
        };
    }

    /** Подпись для поблочных тарифов: половина блока, диапазон или одиночный блок. */
    private function blockLabel(): string
    {
        // Половина блока: block_N_h1 / block_N_h2
        if (preg_match('/^block_(\d+)_h([12])$/', (string) $this->tariff, $m)) {
            $half = $m[2] === '1' ? '1-я половина' : '2-я половина';

            return "Блок {$m[1]} · {$half}";
        }

        // Диапазон блоков (импорт/ручное заполнение start_block/end_block)
        $start = (int) $this->start_block;
        $end = (int) $this->end_block;
        if ($start > 0) {
            return ($end <= 0 || $start === $end) ? "Блок {$start}" : "Блоки {$start}–{$end}";
        }

        // Одиночный блок: block_N
        if (preg_match('/^block_(\d+)$/', (string) $this->tariff, $m)) {
            return "Блок {$m[1]}";
        }

        // Неизвестный/прочий ключ (vip, bundle и т.п.) — отдаём как есть.
        return $this->tariff ?: 'Весь курс';
    }

    /**
     * Канонический набор статусов «оплачено». Разные пути сохранения пишут
     * либо 'paid', либо 'success' — оба означают доступ. Единый источник истины:
     * НЕ дублировать литералы ['paid','success'] по коду (см. scopePaid).
     */
    public const PAID_STATUSES = ['paid', 'success'];

    /** Оплаченные платежи — и 'paid', и 'success'. */
    public function scopePaid(Builder $query): Builder
    {
        return $query->whereIn('status', self::PAID_STATUSES);
    }

    /** Неподтверждённые PayPal-заявки, ожидающие ручной сверки в админке. */
    public function scopePaypalPending(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_PAYPAL)->where('status', 'pending');
    }

    /**
     * Авто-доверенные PayPal-заявки существующих учеников (ruling 22-08-2026):
     * сразу paid, сверка — выборочная и пост-фактум. Фильтр показывает только
     * ещё НЕ просмотренные (verified_at не проставлен).
     */
    public function scopePaypalUnverified(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_PAYPAL)
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNotNull('claim_meta->auto_trusted')
            ->whereNull('claim_meta->verified_at');
    }

    /** Неподтверждённые счета юрлиц, ожидающие сверки банковского поступления. */
    public function scopeInvoicePending(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_INVOICE)->where('status', 'pending');
    }

    /** Неподтверждённые банковские (SEPA/SWIFT) заявки — ручная сверка в админке. */
    public function scopeBankSepaPending(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_BANK_SEPA)->where('status', 'pending');
    }

    /**
     * Авто-доверенные банковские заявки своих учеников: сразу paid, сверка
     * выборочная и пост-фактум (зеркало scopePaypalUnverified).
     */
    public function scopeBankUnverified(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_BANK_SEPA)
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNotNull('claim_meta->auto_trusted')
            ->whereNull('claim_meta->verified_at');
    }

    /** Цвет Filament-бейджа статуса — единая точка вместо дублей match по вьюхам. */
    public static function statusColor(?string $status): string
    {
        return match (true) {
            in_array($status, self::PAID_STATUSES, true) => 'success',
            $status === 'pending' => 'warning',
            $status === 'canceled' => 'danger',
            default => 'gray',
        };
    }

    /** Человекочитаемая подпись статуса платежа. */
    public static function statusLabel(?string $status): string
    {
        return match (true) {
            in_array($status, self::PAID_STATUSES, true) => 'Оплачено',
            $status === 'pending' => 'Ожидает',
            $status === 'canceled' => 'Отменено',
            default => $status ?? 'Не указан',
        };
    }

    /** Только настоящие платежи — учитываются в фин-отчётах и debt-расчётах. */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_conditional', false);
    }

    public function scopeConditional(Builder $query): Builder
    {
        return $query->where('is_conditional', true);
    }

    /**
     * Платежи, пришедшие в кассу школы. Единственные, что образуют ВЫРУЧКУ курса
     * для начисления ЗП. Прямые платежи на личный счёт преподавателя исключены
     * (иначе двойной счёт — он уже держит всю сумму). NB: доступ и отчёт должников
     * этим НЕ ограничиваются — там учитываются все оплаченные платежи.
     */
    public function scopeSchoolReceived(Builder $query): Builder
    {
        return $query->where('received_account', self::RECEIVED_SCHOOL);
    }

    /** Прямые платежи на личный счёт преподавателя — источник авто-зачёта в гонорар. */
    public function scopeTeacherReceived(Builder $query): Builder
    {
        return $query->where('received_account', self::RECEIVED_TEACHER);
    }

    /**
     * Реально оплаченные и ещё не зачтённые в стоимость тарифа суммы: и депозиты
     * (бронь), и пробные занятия — обе засчитываются при последующей покупке курса.
     */
    public function scopeUnconsumedDeposits(Builder $query): Builder
    {
        return $query
            ->whereIn('tariff', ['deposit', 'trial'])
            ->whereNull('deposit_consumed_at')
            ->whereIn('status', self::PAID_STATUSES);
    }

    /**
     * Есть ли уже другой незавершённый (pending) заказ этого пользователя на этот
     * курс. Используется, чтобы не дать создать второй pending, который зачтёт
     * ту же ещё не потраченную бронь/пробное занятие — иначе оба заказа получают
     * полную скидку на один и тот же депозит, и оба потом оплачиваются
     * (money-core, H071 #2: депозит кредитуется на несколько pending).
     */
    public function scopeHasOtherPendingOrderForCourse(Builder $query, int $userId, int $courseId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'pending')
            ->whereNotIn('tariff', ['deposit', 'trial']);
    }

    // ==========================================
    // АВТОМАТИЗАЦИЯ ПРИ СОЗДАНИИ ИЛИ ИЗМЕНЕНИИ
    // ==========================================
    protected static function booted()
    {
        // 0. Инвариант прямого платежа: teacher_personal обязан называть
        // преподавателя-получателя; для кассы школы поле-получатель чистим.
        // Совпадение валюты (foreign_currency == payout_currency преподавателя)
        // проверяет форма — на уровне модели её знать не обязаны.
        static::saving(function (Payment $payment): void {
            if ($payment->received_account === self::RECEIVED_TEACHER) {
                if (empty($payment->received_by_teacher_id)) {
                    throw new \InvalidArgumentException(
                        'Прямой платёж на личный счёт преподавателя требует указания преподавателя-получателя (received_by_teacher_id).'
                    );
                }
            } else {
                $payment->received_account = self::RECEIVED_SCHOOL;
                $payment->received_by_teacher_id = null;
            }
        });

        // 1. Срабатывает при СОЗДАНИИ нового платежа
        static::created(function (Payment $payment) {
            // Ловим и 'success', и 'paid' (в зависимости от того, как сохраняет админка)
            if (in_array($payment->status, self::PAID_STATUSES, true)) {
                self::fireOnPaid($payment);
            }
        });

        // 2. Срабатывает при ИЗМЕНЕНИИ существующего платежа
        static::updated(function (Payment $payment) {
            $enteredPaid = ! in_array($payment->getOriginal('status'), self::PAID_STATUSES, true);
            if ($payment->isDirty('status')
                && in_array($payment->status, self::PAID_STATUSES, true)
                && (! config('features.checkout_promo_reservations') || $enteredPaid)
            ) {
                self::fireOnPaid($payment);
            }

            // Откатываем списанную прану и зачтённый реферальный кредит, если оплата сорвалась.
            // ВАЖНО: канон статуса в payments — 'canceled' (одно L; см. миграцию и админку
            // PaymentResource). 'cancelled' оставлен оборонительно для чужих написаний.
            if ($payment->isDirty('status') && in_array($payment->status, ['failed', 'canceled', 'cancelled'], true)) {
                $payment->refundPranaIfSpent();
                $payment->refundReferralCreditIfApplied();

                if (in_array($payment->getOriginal('status'), self::PAID_STATUSES, true)) {
                    if (config('features.checkout_promo_reservations')
                        && ! $payment->is_conditional
                        && $payment->promo_code_id
                    ) {
                        $payment->promoCode?->releaseRedemption();
                    }

                    if (config('features.checkout_deposit_reversal')) {
                        $payment->restoreDepositsAfterReversal();
                    }

                    // Снимаем нулевые access-only siblings этого платежа — иначе
                    // оплаченные «в кредит доступа» блоки остались бы открыты
                    // после отката основного платежа.
                    app(BlockAccessMaterializer::class)->removeSiblingsOf($payment);
                    $payment->reconcileAccessAfterReversal();

                    // H3334: неактивированный подарочный сертификат отзывается,
                    // если оплата за него вернулась. Уже активированный не трогаем.
                    if ($payment->isGiftCertificate()) {
                        app(GiftCertificateService::class)->revokeForPayment($payment);
                    }
                }
            }
        });
    }

    /**
     * Депозит и обычная оплата идут разными путями: депозит НЕ открывает
     * доступ к группам, обычная оплата дополнительно гасит ранее
     * оплаченные депозиты по тому же курсу.
     */
    private static function fireOnPaid(Payment $payment): void
    {
        // H1645: штамп момента первого входа в оплаченный статус — резервная
        // опора resurrection-guard'а (hasPriorPaidTransition), не зависящая от
        // payment_audits. Один раз, никогда не перезаписывается.
        //
        // ВНИМАНИЕ: fireOnPaid вызывается ИЗНУТРИ static::updated — одного из
        // НЕСКОЛЬКИХ слушателей события 'updated' (PaymentObserver и другие
        // регистрируются отдельно). Нельзя стампить через $payment->save()/
        // updateQuietly() — они вызывают syncOriginal()/syncChanges() на ЭТОМ
        // же инстансе и тем самым портят isDirty('status')/wasChanged('status')
        // для слушателей, которые выполнятся ПОСЛЕ нас в том же проходе
        // диспетчера (PaymentObserver::updated проверяет isDirty('status') —
        // с save() внутри fireOnPaid реферальная/партнёрская награда переставала
        // начисляться на pending->paid переходе). setAttribute() — только
        // in-memory, без events и без синка $original; персист — отдельным
        // withoutEvents-запросом, не через текущий инстанс.
        if ($payment->first_paid_at === null) {
            $timestamp = now();
            Payment::withoutEvents(
                fn () => Payment::query()->whereKey($payment->getKey())->update(['first_paid_at' => $timestamp])
            );
            $payment->setAttribute('first_paid_at', $timestamp);
        }

        // Системный расход / возврат и выплата ЗП преподавателю — только
        // бухгалтерская строка. Никакого доступа, писем, праны, Telegram.
        if ($payment->isExpense() || $payment->isSalaryPayout()) {
            return;
        }

        // Пожертвование — донорская рамка без встречных благ (решение MG 23-08,
        // план института N2): доступ/группы/членство/лиды/депозиты не трогаем.
        // Единственное побочное действие — благодарность при согласии донора (N3).
        if ($payment->isDonation()) {
            $payment->processDonationGratitude();

            return;
        }

        if ($payment->isDeposit()) {
            $payment->processDeposit();

            return;
        }

        // Пробное занятие: открываем доступ к одному уроку, курс/группы не трогаем.
        if ($payment->isTrial()) {
            $payment->processTrial();

            return;
        }

        // Марафон «с проверкой»: доступа к курсу/группам не даёт — только
        // помечает энрол оплаченным (H471). course_id у такого платежа нет.
        if ($payment->isMarathonPaid()) {
            $payment->processMarathonPaid();

            return;
        }

        // Подарочный сертификат (H3334): доступ покупателю не открываем —
        // выпускаем одноразовый код; доступ получит активировавший по тарифной
        // модели (см. GiftCertificateService::redeem).
        if ($payment->isGiftCertificate()) {
            $payment->processGiftCertificate();

            return;
        }

        $payment->processSuccessfulPayment();

        // Conditional access под обещание — реальных денег нет,
        // депозит гасить не за что.
        if (! $payment->is_conditional) {
            $payment->consumeDepositsForCourse();
            $payment->reconcileConditionalGrants();

            // Self-service: должник сам погасил обещание/рассрочку — закрываем
            // привязанное обещание, чтобы у куратора не висел открытый долг.
            app(PromiseAutoFulfiller::class)->handlePaidPayment($payment);

            // Мульти-блочный доступ: платёж с диапазоном блоков несёт лишь один
            // ключ — дорисовываем недостающие ключи блоков нулевыми access-only
            // строками (иначе оплаченные блоки N+1..M остаются закрыты). Лечит и
            // менеджерский PromiseFulfillment.
            app(BlockAccessMaterializer::class)->materialize($payment);
        }
    }

    /**
     * Stamp Lead.converted_at for Rate B / CRM (H2186).
     *
     * Prefer payment.lead_id; if empty, match the newest unconverted Lead by
     * buyer email (same heuristic as DepositController / TrialController) and
     * backfill lead_id without re-entering model events.
     *
     * Deposit / trial / marathon call this always. Ordinary course purchase
     * calls it only when features.lead_converted_at_on_course_paid is ON
     * (default OFF — prod inert until a human enables the flag).
     */
    public function markLinkedLeadConverted(): void
    {
        $lead = $this->lead;

        if ($lead === null) {
            $email = $this->user?->email;
            if (filled($email)) {
                $lead = Lead::query()
                    ->where('email', $email)
                    ->whereNull('converted_at')
                    ->latest()
                    ->first();

                if ($lead !== null && $this->lead_id === null && $this->getKey() !== null) {
                    static::withoutEvents(
                        fn () => static::query()->whereKey($this->getKey())->update(['lead_id' => $lead->id])
                    );
                    $this->setAttribute('lead_id', $lead->id);
                }
            }
        }

        $lead?->markConverted();
    }

    // ==========================================
    // ГЛАВНЫЙ МЕТОД: ЗАПУСКАЕТ ВСЕ ПРОЦЕССЫ
    // ==========================================
    public function processSuccessfulPayment()
    {
        DB::transaction(function () {
            $this->grantAccess();
            $this->enrollInCourse();

            // H2644: оплаченный период клубного членства. No-op для любого
            // платежа не на курсе-членстве и при выключенном флаге. Добавлено
            // РЯДОМ с существующими шагами — grantAccess() не тронут (fence rule 3):
            // группу по-прежнему выдаёт он, здесь только заводится период, по
            // истечении которого демон это право снимет.
            if (config('features.club_membership')) {
                app(ClubMembershipService::class)->syncFromPayment($this);
            }

            // H2495: provider-independent Grammar Lab grant. No-op unless
            // the course slug is in grammar_lab.entitlement_course_slugs or
            // subscription_course_slug. grantAccess() is untouched.
            app(GrammarLabEntitlementService::class)->syncFromPayment($this);

            // Для conditional Payment (доступ под обещание) пропускаем
            // welcome-email и начисление праны — деньги не пришли,
            // не дарим бонусы и не флудим письмами.
            if (! $this->is_conditional) {
                $this->sendWelcomeEmailIfNeeded();
                $this->sendCourseWelcomeEmailIfFirstForCourse();
                // H1286: чек-приветствие на каждую реальную оплату + онбординг
                // первой недели (день 1 / день 5) на первую оплату курса.
                // Событий оплаты в приложении нет — send добавлен в оркестратор
                // рядом с существующими, grantAccess() не тронут (fence rule 3).
                $this->sendPurchaseConfirmation();
                $this->scheduleOnboardingIfFirstForCourse();
                $this->awardPranaForPurchase();
                // Засчитываем использование промокода только по подтверждённой
                // оплате (раньше инкремент стоял на создании pending, из-за чего
                // брошенные чекауты исчерпывали usage_limit).
                $this->promoCode?->markRedeemed();

                // H2186: Rate B instrumentation — OFF by default (spec §2.4 residual).
                if (config('features.lead_converted_at_on_course_paid')) {
                    $this->markLinkedLeadConverted();
                }
            }
        });

        // Уведомление кураторам — только по реальным (не conditional) оплатам.
        // Conditional-доступ под обещание уведомляется в ConditionalAccessGranter.
        if (! $this->is_conditional) {
            app(CuratorNotifier::class)->paymentPaid($this);
        }

        if (! $this->user_id) {
            return;
        }

        // Telegram — через очередь, чтобы не держать row-lock webhook'а
        // во время синхронного HTTP-вызова к api.telegram.org.
        $courseName = $this->course->title ?? 'Обучающий материал';
        $url = url('/login');

        if ($this->is_conditional) {
            $scope = $this->tariff === 'full'
                ? 'полный курс'
                : 'блок '.preg_replace('/[^\d]+/', '', (string) $this->tariff);

            $promise = $this->linkedPromise;
            $deadline = $promise?->promised_at?->format('d.m.Y');

            $text = "📅 <b>Доступ открыт по договорённости</b>\n\n";
            $text .= "Намасте! Открыт {$scope} курса <b>«{$courseName}»</b> ";
            $text .= $deadline ? "до <b>{$deadline}</b>." : 'до согласованного срока.';
            $text .= "\n\n<a href='{$url}'>Перейти в личный кабинет</a>";
        } else {
            $text = "🎉 <b>Оплата успешно получена!</b>\n\n";
            $text .= "Намасте! Ваш доступ к курсу <b>«{$courseName}»</b> открыт.\n\n";
            $text .= "Можете приступать к занятиям прямо сейчас:\n";
            $text .= "<a href='{$url}'>Перейти в личный кабинет</a>";
        }

        SendTelegramMessageJob::dispatch($this->user_id, $text);
    }

    // ==========================================
    // ДЕПОЗИТ (бронь курса) — отдельный путь
    // ==========================================
    public function processDeposit(): void
    {
        DB::transaction(function () {
            // НЕ вызываем grantAccess: депозит не открывает платные уроки.
            // Открытые уроки уже доступны любому залогиненному пользователю.
            $this->sendWelcomeEmailIfNeeded();

            $this->markLinkedLeadConverted();
        });

        // Уведомление кураторам о брони (депозите).
        app(CuratorNotifier::class)->depositReceived($this);

        if (! $this->user_id) {
            return;
        }

        // E-mail-подтверждение брони (со ссылкой на чат курса). Telegram есть не
        // у всех — без письма повторные студенты не получали бы по брони ничего.
        if ($this->user && $this->user->email && $this->course) {
            Mail::to($this->user->email)
                ->send(new DepositReceivedMail($this->user, $this->course));
        }

        $courseName = $this->course->title ?? 'курс';
        $url = url('/login');

        $text = "📌 <b>Бронь курса принята</b>\n\n";
        $text .= "Намасте! Мы получили предоплату за курс <b>«{$courseName}»</b>. ";
        $text .= 'Сумма зачтётся при оплате полного тарифа.';
        $text .= "\n\n<a href='{$url}'>Личный кабинет</a>";

        SendTelegramMessageJob::dispatch($this->user_id, $text);
    }

    // ==========================================
    // ПРОБНОЕ ЗАНЯТИЕ — отдельный путь
    // ==========================================
    public function processTrial(): void
    {
        $lessonId = $this->course?->trial_lesson_id;

        DB::transaction(function () use ($lessonId) {
            // Доступ к курсу/группам НЕ открываем — только разовый grant на пробный урок.
            // Создаём напрямую (не через ConditionalAccessGranter::grantLesson), чтобы
            // оплаченный доступ не блокировался флагом «неблагонадёжный» и не слал свой
            // Telegram (своё подтверждение шлём ниже). Идемпотентно: дубль активного
            // гранта на тот же урок не создаём (повторный paid-вебхук).
            if ($lessonId) {
                $exists = LessonAccessGrant::query()
                    ->where('user_id', $this->user_id)
                    ->where('lesson_id', $lessonId)
                    ->active()
                    ->exists();

                if (! $exists) {
                    LessonAccessGrant::create([
                        'user_id' => $this->user_id,
                        'lesson_id' => $lessonId,
                        'course_id' => $this->course_id,
                        'reason' => 'оплачено пробное занятие',
                        'granted_at' => now(),
                        // expires_at = null → доступ навсегда.
                    ]);
                }
            } else {
                Log::warning('Payment::processTrial — у курса не задан trial_lesson_id', [
                    'payment_id' => $this->id,
                    'course_id' => $this->course_id,
                ]);
            }

            $this->sendWelcomeEmailIfNeeded();
            $this->markLinkedLeadConverted();
        });

        app(CuratorNotifier::class)->depositReceived($this);

        if (! $this->user_id) {
            return;
        }

        // Данные занятия — из события расписания курса. Прошедшее занятие → пробное
        // открывает ЗАПИСЬ (Zoom-ссылка не нужна); предстоящее → живое подключение.
        $schedule = $this->course?->trialSchedule;
        $startsAt = $schedule?->start;
        $isRecording = $startsAt && $startsAt->isPast();
        $zoomLink = $isRecording ? null : $schedule?->link;

        // Письмо: живое → ссылка на Zoom; запись → указание на личный кабинет.
        if ($this->user && $this->user->email) {
            Mail::to($this->user->email)
                ->send(new TrialZoomLinkMail($this->user, $this->course, $zoomLink, $startsAt, (bool) $isRecording));
        }

        $courseName = $this->course->title ?? 'курс';
        $url = url('/login');

        $text = "🎟 <b>Пробное занятие оплачено</b>\n\n";
        if ($isRecording) {
            $text .= "Намасте! Запись занятия курса <b>«{$courseName}»</b>";
            $text .= ' от '.$startsAt->translatedFormat('d F').' открыта в личном кабинете.';
            $text .= "\n";
        } else {
            $text .= "Намасте! Вы записаны на живое занятие курса <b>«{$courseName}»</b>";
            if ($startsAt) {
                $text .= ' — '.$startsAt->translatedFormat('d F, H:i').' (МСК)';
            }
            $text .= ".\n";
            if ($zoomLink) {
                $text .= "\n🔗 <a href='{$zoomLink}'>Подключиться к Zoom</a>\n";
            }
        }
        $text .= 'Сумма зачтётся при оплате полного тарифа.';
        $text .= "\n\n<a href='{$url}'>Личный кабинет</a>";

        SendTelegramMessageJob::dispatch($this->user_id, $text);
    }

    // ==========================================
    // МАРАФОН «С ПРОВЕРКОЙ» ₽500 — отдельный путь (H471)
    // ==========================================
    /**
     * Доступа к курсу/группам НЕ даёт — только помечает MarathonEnrollment
     * оплаченным. Энрол ищется по lead_id (H446/H464 уже гарантируют один
     * энрол на лида в рамках лендинга марафона — новой колонки-связки не
     * потребовалось). Идемпотентно: paid_at выставляется один раз.
     */
    public function processMarathonPaid(): void
    {
        $enrollment = MarathonEnrollment::where('lead_id', $this->lead_id)->first();

        if (! $enrollment) {
            Log::warning('Payment::processMarathonPaid — MarathonEnrollment не найден', [
                'payment_id' => $this->id,
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        if ($enrollment->paid_at === null) {
            $enrollment->update(['paid_at' => now()]);
        }

        $this->markLinkedLeadConverted();

        // Подтверждение шлём в Telegram лида (канал Phase 2 — TelegramDeliveryChannel
        // по telegram_chat_id лида), не SendTelegramMessageJob (тот резолвит по
        // User, отдельная от лид-магнит-бота привязка — марафонский лид её не имеет).
        $lead = $this->lead;
        if ($lead && $lead->telegram_chat_id) {
            $text = '✅ <b>Оплата получена</b>'."\n\n"
                .'Трек «с проверкой» марафона оплачен. Ваша практика Дней 1–2 '
                .'разбирается куратором, и вам гарантировано место на живой '
                .'консультации Дня 3 — ваш вопрос разберут лично.';

            app(DeliveryChannelManager::class)
                ->get('telegram')
                ->sendMessage((string) $lead->telegram_chat_id, $text);
        }

        app(CuratorNotifier::class)->paymentPaid($this);
    }

    /**
     * Подарочный сертификат (H3334) — отдельный путь: доступ покупателю НЕ
     * открывается (никаких групп/писем-доступа/праны за «покупку курса»).
     * Вместо этого выпускается GiftCertificate с одноразовым хэшированным
     * кодом; сырой код уходит покупателю одним письмом с PDF.
     * Идемпотентно: повторный paid-переход не перегенерирует код.
     */
    public function processGiftCertificate(): void
    {
        app(GiftCertificateService::class)->issueForPayment($this);
    }

    /**
     * Пожертвование (план института N2/N3): при paid фиксируем благодарность,
     * если донор дал явное согласие на /mecenaty. Идемпотентно по уникальному
     * payment_id; без согласия — ничего. Никаких других побочных действий:
     * доступ/членство/лиды не трогаются (см. fireOnPaid).
     */
    public function processDonationGratitude(): void
    {
        $gratitude = is_array($this->claim_meta) ? ($this->claim_meta['gratitude'] ?? null) : null;

        if (! is_array($gratitude)
            || empty($gratitude['consent'])
            || blank($gratitude['name'] ?? null)) {
            return;
        }

        DonationGratitude::firstOrCreate(
            ['payment_id' => $this->getKey()],
            [
                'name_display' => trim((string) $gratitude['name']),
                // Ратифицировано MG 23-08: сумма в реестре — только по отдельной
                // просьбе конкретного человека (чекбокс «показать сумму»).
                'show_amount' => (bool) ($gratitude['show_amount'] ?? false),
            ]
        );
    }

    /**
     * Гасит ранее оплаченные и ещё не зачтённые депозиты по тому же курсу,
     * что и текущий «реальный» платёж — но только НА СУММУ, реально вычтенную
     * из цены этого заказа (deposit_credit_applied, пишется в чекауте).
     * Остаток депозита переживает покупку дешевле депозита и зачтётся в
     * следующий заказ (money-core, H071 #10: раньше гасился весь депозит
     * целиком, излишек молча сгорал).
     *
     * Легаси-платежи без deposit_credit_applied (null — созданы до колонки)
     * гасят всё целиком, как раньше. updateQuietly — чтобы не перезапустить
     * booted-хуки и не зациклить обсёрвер.
     */
    public function consumeDepositsForCourse(): void
    {
        if (! $this->course_id) {
            return;
        }

        $deposits = self::query()
            ->where('user_id', $this->user_id)
            ->where('course_id', $this->course_id)
            ->unconsumedDeposits()
            ->orderBy('created_at')
            ->get();

        if ($this->deposit_credit_applied === null) {
            $deposits->each(fn (self $deposit) => $deposit->updateQuietly([
                'consumed_amount' => $deposit->amount,
                'deposit_consumed_at' => now(),
            ]));

            return;
        }

        $remaining = (float) $this->deposit_credit_applied;

        foreach ($deposits as $deposit) {
            if ($remaining <= 0) {
                break;
            }

            $alreadyConsumed = (float) ($deposit->consumed_amount ?? 0);
            $residual = (float) $deposit->amount - $alreadyConsumed;

            if ($residual <= 0) {
                // Стоимость выбрана ранее, но штамп не поставлен — дочищаем.
                $deposit->updateQuietly(['deposit_consumed_at' => now()]);

                continue;
            }

            $take = min($residual, $remaining);
            $remaining -= $take;
            $newConsumed = $alreadyConsumed + $take;

            $deposit->updateQuietly([
                'consumed_amount' => $newConsumed,
                'deposit_consumed_at' => $newConsumed >= (float) $deposit->amount ? now() : null,
            ]);
        }
    }

    /**
     * Возвращает депозитный зачёт при реальном paid/success → failed/canceled.
     * Маркер покупки deposit_credit_applied намеренно не меняется: он остаётся
     * аудит-следом и нужен, если платеж позже легитимно вернут в paid.
     *
     * Consumption создаётся FIFO, а откатывается LIFO (новейшие deposit/trial
     * строки первыми). Легаси-покупки с null-маркером неоднозначны и пропускаются.
     */
    private function restoreDepositsAfterReversal(): void
    {
        if (! $this->user_id || ! $this->course_id || $this->deposit_credit_applied === null) {
            return;
        }

        $expectedCents = (int) round((float) $this->deposit_credit_applied * 100);
        if ($expectedCents <= 0) {
            return;
        }

        $remainingCents = DB::transaction(function () use ($expectedCents): int {
            $remaining = $expectedCents;

            $deposits = self::query()
                ->where('user_id', $this->user_id)
                ->where('course_id', $this->course_id)
                ->whereIn('tariff', ['deposit', 'trial'])
                ->paid()
                ->real()
                ->whereNotNull('consumed_amount')
                ->where('consumed_amount', '>', 0)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            foreach ($deposits as $deposit) {
                if ($remaining <= 0) {
                    break;
                }

                $consumedCents = (int) round((float) $deposit->consumed_amount * 100);
                $restoreCents = min($remaining, $consumedCents);
                $newConsumedCents = $consumedCents - $restoreCents;
                $remaining -= $restoreCents;

                $deposit->updateQuietly([
                    'consumed_amount' => $newConsumedCents > 0 ? $newConsumedCents / 100 : null,
                    'deposit_consumed_at' => null,
                ]);
            }

            return $remaining;
        });

        if ($remainingCents > 0) {
            Log::warning('Payment deposit restoration shortfall', [
                'payment_id' => $this->id,
                'deposit_credit_applied' => $expectedCents / 100,
                'restored_amount' => ($expectedCents - $remainingCents) / 100,
                'shortfall' => $remainingCents / 100,
            ]);
        }
    }

    /**
     * Авто-сверка с conditional-доступом «под обещание». Когда приходит реальная
     * оплата того, что раньше открыли под честное слово, перекрытые conditional
     * Payments надо снять (иначе они дублируют блок и маскируют покупку в витрине),
     * а связанное обещание — закрыть, если по нему больше не осталось открытых
     * conditional-доступов.
     *
     * Соответствие тарифов — по containment-модели (как upgradeCreditForUser):
     *   реальный 'full'    перекрывает любой conditional курса (full + block_%);
     *   реальный 'block_N' перекрывает только conditional на тот же блок.
     */
    public function reconcileConditionalGrants(): void
    {
        if ($this->is_conditional || ! $this->user_id || ! $this->course_id) {
            return;
        }

        $query = self::query()
            ->conditional()
            ->where('user_id', $this->user_id)
            ->where('course_id', $this->course_id);

        if ($this->tariff === 'full') {
            $query->where(fn (Builder $q) => $q->where('tariff', 'full')->orWhere('tariff', 'like', 'block_%'));
        } else {
            $query->where('tariff', $this->tariff);
        }

        $conditionals = $query->get(['id', 'linked_promise_id']);
        if ($conditionals->isEmpty()) {
            return;
        }

        $promiseIds = $conditionals->pluck('linked_promise_id')->filter()->unique();

        // Снимаем перекрытые conditional-платежи. Mass-delete не триггерит
        // Eloquent-события — это намеренно: аудит/sheet-sync для «фантомных»
        // нулевых платежей не нужны (как и в ConditionalAccessGranter::revoke).
        self::query()->whereIn('id', $conditionals->pluck('id'))->delete();

        // Закрываем обещание, если у него не осталось открытых conditional-доступов
        // (full-обещание могло открыть несколько блоков — частичная оплата его не гасит).
        foreach ($promiseIds as $promiseId) {
            $promise = PaymentPromise::find($promiseId);

            if (! $promise instanceof PaymentPromise || ! $promise->isUnmet()) {
                continue;
            }

            $stillOpen = self::query()
                ->conditional()
                ->where('linked_promise_id', $promiseId)
                ->exists();

            if ($stillOpen) {
                continue;
            }

            $promise->update([
                'status' => PaymentPromise::STATUS_FULFILLED,
                'fulfilled_at' => now(),
                'fulfilled_payment_id' => $this->id,
                'actual_paid_at' => now(),
            ]);
        }
    }

    // ==========================================
    // ЛОГИКА ВЫДАЧИ ДОСТУПА И ГРУПП
    // ==========================================
    public function grantAccess(): void
    {
        $user = $this->user;
        $course = $this->course;

        if (! $course) {
            Log::warning("grantAccess: платёж #{$this->id} без курса, пропускаем.");

            return;
        }

        if (! $user) {
            Log::warning("grantAccess: платёж #{$this->id} без пользователя, пропускаем.");

            return;
        }

        // Все группы, привязанные к этому курсу через pivot course_group
        $groupIds = $course->groups()->pluck('groups.id')->toArray();

        if (empty($groupIds)) {
            // H2304 spec 2: fail closed на ВСЕХ платных маршрутах, а не только в
            // Tochka-вебхуке (H2085). Log-and-return здесь означал «оплачено, но
            // доступа нет» для zero-price checkout, Filament, PayPal и импорта.
            // Money-контур: throw за флагом (дефолт OFF), включение — ops-шаг.
            $msg = "grantAccess: у курса '{$course->title}' (id={$course->id}) ".
                "нет привязанных групп (payment #{$this->id}). ".
                'Оплата не применена: привяжите группу в админке курса и повторите.';
            Log::error($msg);

            if (config('features.grant_access_fail_closed')) {
                throw new \RuntimeException($msg);
            }

            return;
        }

        // syncWithoutDetaching — добавляет новые связи, не удаляя существующие
        $user->groups()->syncWithoutDetaching($groupIds);

        Log::info(
            "grantAccess: студент #{$user->id} ({$user->email}) добавлен в ".
            count($groupIds)." групп(у/ы) курса '{$course->title}'."
        );
    }

    // ==========================================
    // АВТО-ЗАПИСЬ «ОБУЧАЕТСЯ НА КУРСАХ» (pivot course_user)
    // ==========================================
    // grantAccess() выдаёт доступ к урокам через группы. Этот метод дополнительно
    // создаёт запись «Записался» в course_user, которую раньше ставили вручную
    // («Записать на курс» в карточке студента). Существующую строку НЕ трогаем —
    // чтобы не затереть ручной статус (Рассрочка / Льготник / Покинул / Выпускник).
    public function enrollInCourse(): void
    {
        if (! $this->user_id || ! $this->course_id) {
            return;
        }

        $already = $this->user->courses()
            ->where('courses.id', $this->course_id)
            ->exists();

        if ($already) {
            return;
        }

        // attach (НЕ syncWithoutDetaching: тот перезаписал бы pivot существующей
        // пары). note по умолчанию остаётся null.
        $this->user->courses()->attach($this->course_id, ['status' => 'Записался']);

        Log::info("enrollInCourse: студент #{$this->user_id} записан на курс #{$this->course_id} (Записался).");
    }

    /**
     * При откате оплаченного платежа закрываем групповой доступ только если у
     * студента больше нет других access-granting платежей на этот курс.
     * course_user не трогаем: это история/админское состояние, а не ACL.
     */
    public function reconcileAccessAfterReversal(): void
    {
        if (! $this->user_id || ! $this->course_id || ! $this->user || ! $this->course) {
            return;
        }

        if (! $this->grantsCourseAccess()) {
            return;
        }

        $courseGroupIds = $this->course->groups()->pluck('groups.id')->all();
        if (empty($courseGroupIds)) {
            return;
        }

        if ($this->userHasRemainingCourseAccess((int) $this->course_id)) {
            return;
        }

        $neededByOtherCourses = Course::query()
            ->whereKeyNot($this->course_id)
            ->whereHas('groups', fn (Builder $q) => $q->whereIn('groups.id', $courseGroupIds))
            ->whereHas('payments', fn (Builder $q) => $this->accessGrantingPaymentsForUser($q))
            ->with(['groups' => fn ($q) => $q->whereIn('groups.id', $courseGroupIds)])
            ->get()
            ->flatMap(fn (Course $course) => $course->groups->pluck('id'))
            ->unique()
            ->all();

        $detachIds = array_values(array_diff($courseGroupIds, $neededByOtherCourses));
        if (empty($detachIds)) {
            return;
        }

        $this->user->groups()->detach($detachIds);
    }

    private function grantsCourseAccess(): bool
    {
        return ! $this->isDeposit()
            && ! $this->isTrial()
            && ! $this->isExpense()
            && ! $this->isSalaryPayout();
    }

    private function userHasRemainingCourseAccess(int $courseId): bool
    {
        return self::query()
            ->where('course_id', $courseId)
            ->where(fn (Builder $q) => $this->accessGrantingPaymentsForUser($q))
            ->exists();
    }

    private function accessGrantingPaymentsForUser(Builder $query): Builder
    {
        return $query
            ->where('user_id', $this->user_id)
            ->paid()
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout']);
    }

    // ==========================================
    // НАЧИСЛЕНИЕ ПРАНЫ ЗА УСПЕШНУЮ ОПЛАТУ
    // ==========================================
    public function awardPranaForPurchase(): void
    {
        if (! $this->user) {
            return;
        }

        // Бесплатные «оплаты» (промокод на 100% и т.п.) не должны давать прану.
        if ((float) $this->amount <= 0) {
            return;
        }

        // Идемпотентно по этому платежу — индекс не даст начислить дважды.
        app(PranaService::class)
            ->award($this->user, 'payment_success', $this);
    }

    // ==========================================
    // ВОЗВРАТ ПРАНЫ ПРИ НЕУДАЧЕ
    // ==========================================
    public function refundPranaIfSpent(): void
    {
        if (! $this->user || (int) $this->prana_spent <= 0) {
            return;
        }

        app(PranaService::class)->refund(
            $this->user,
            (int) $this->prana_spent,
            'refund_failed',
            $this,
        );
    }

    /**
     * Вернуть зачтённый реферальный кредит в кошелёк, если оплата сорвалась.
     * Зеркалит refundPranaIfSpent(). Идемпотентно: обнуляем applied после возврата,
     * чтобы повторный failed→cancelled не вернул кредит дважды.
     */
    public function refundReferralCreditIfApplied(): void
    {
        $applied = (float) ($this->referral_credit_applied ?? 0);
        if (! $this->user || $applied <= 0) {
            return;
        }

        $this->user->increment('referral_credit', $applied);
        $this->updateQuietly(['referral_credit_applied' => 0]);
    }

    // ==========================================
    // ГЕНЕРАЦИЯ ПАРОЛЯ И ОТПРАВКА ПИСЬМА
    // ==========================================
    public function sendWelcomeEmailIfNeeded()
    {
        $student = $this->user;

        if (! $student) {
            Log::error('Студент не найден для платежа ID: '.$this->id);

            return;
        }

        // Админам welcome-письмо с генерируемым паролем не нужно —
        // у них уже есть свой пароль, перезапись его сломает доступ.
        if ($student->is_admin) {
            return;
        }

        // Пароль генерируем и шлём РОВНО ОДИН РАЗ на аккаунт. Иначе повторный вход
        // платежа в paid-статус (round-trip canceled→paid, ре-сейв legacy 'success',
        // поздний вебхук) при единственной оплате снова перегенерировал бы пароль,
        // который студент уже сам сменил, и ломал ему вход (money-core H071 #15).
        if ($student->welcome_email_sent_at !== null) {
            return;
        }

        // Считаем успешные оплаты
        $paymentsCount = $student->payments()->paid()->count();

        // Пишем в лог, сколько оплат нашла система
        Log::info("Попытка отправки письма. Студент: {$student->email}. Найдено успешных оплат: {$paymentsCount}");

        // Если это первая оплата
        if ($paymentsCount === 1) {
            Log::info("Генерируем пароль и отправляем письмо студенту: {$student->email}");

            $newPassword = Str::random(8);
            $student->password = Hash::make($newPassword);
            $student->welcome_email_sent_at = now();
            $student->save();

            Mail::to($student->email)->send(new StudentWelcomeMail($student, $newPassword));

            Log::info('Письмо успешно передано в почтовик!');
        } else {
            Log::warning("Письмо НЕ отправлено, так как это не первая оплата (счетчик: {$paymentsCount})");
        }
    }

    // ==========================================
    // БЛАГОДАРНОСТЬ ЗА ПЕРВУЮ ОПЛАТУ КОНКРЕТНОГО КУРСА
    // ==========================================
    // В отличие от sendWelcomeEmailIfNeeded() (первая оплата вообще, с паролем),
    // это письмо уходит при первой реальной оплате ИМЕННО ЭТОГО курса — в т.ч.
    // вернувшемуся студенту, берущему 2-й/3-й курс. Содержит ссылку на чат курса.
    public function sendCourseWelcomeEmailIfFirstForCourse(): void
    {
        $student = $this->user;

        if (! $student || ! $student->email || ! $this->course_id || ! $this->course) {
            return;
        }

        // Тестовому админу курсовое письмо не шлём (как и welcome).
        if ($student->is_admin) {
            return;
        }

        // Реальные (доступо-открывающие) оплаты этого курса. Бронь/пробное/conditional
        // не считаем. Текущий платёж уже сохранён как paid (fireOnPaid вызывается
        // после save), поэтому === 1 означает «это первая оплата курса». При покупке
        // block_1, затем block_2 того же курса письмо уйдёт лишь на первой.
        $count = $student->payments()
            ->where('course_id', $this->course_id)
            ->paid()
            ->real()
            ->whereNotIn('tariff', ['deposit', 'trial'])
            ->count();

        if ($count === 1) {
            Mail::to($student->email)
                ->send(new CourseWelcomeMail($student, $this->course));
        }
    }

    // ==========================================
    // ПОДТВЕРЖДЕНИЕ ПОКУПКИ — ЧЕК-ПРИВЕТСТВИЕ (H1286)
    // ==========================================
    // В отличие от CourseWelcomeMail (первая оплата курса) уходит на КАЖДУЮ
    // реальную оплату: чек — атрибут транзакции, а не знакомства. Conditional
    // отфильтрован вызывающим кодом (processSuccessfulPayment), депозит/пробное
    // идут своими путями и сюда не попадают.
    private function sendPurchaseConfirmation(): void
    {
        $student = $this->user;

        if (! $student || ! $student->email) {
            return;
        }

        // Тестовому админу чек не шлём (как welcome и course-welcome).
        if ($student->is_admin) {
            return;
        }

        Mail::to($student->email)->send(new PurchaseConfirmationMail($this));
    }

    // ==========================================
    // ОНБОРДИНГ ПЕРВОЙ НЕДЕЛИ: ДЕНЬ 1 И ДЕНЬ 5 (H1286)
    // ==========================================
    // Прод-SMTP сломан (#504), поэтому рабочая доставка — Telegram/VK через
    // существующий механизм ScheduledReminder (reminders:send-due, идемпотентность
    // по sent_at). to_email сознательно false: email-версии дней 1/5 — отдельные
    // свёрстанные Mailable (OnboardingDay1Mail/OnboardingDay5Mail), их подключит
    // ESP-гейт (H1147); генерическим ScheduledReminderMail их не дублируем.
    // Условие «первая оплата ИМЕННО ЭТОГО курса» — то же, что у CourseWelcomeMail:
    // покупателю 2-го блока онбординг курса, в котором он уже занимается, не нужен.
    private function scheduleOnboardingIfFirstForCourse(): void
    {
        $student = $this->user;

        if (! $student || ! $this->course_id || ! $this->course) {
            return;
        }

        if ($student->is_admin) {
            return;
        }

        $count = $student->payments()
            ->where('course_id', $this->course_id)
            ->paid()
            ->real()
            ->whereNotIn('tariff', ['deposit', 'trial'])
            ->count();

        if ($count !== 1) {
            return;
        }

        $title = $this->course->title;
        $url = url('/login');

        ScheduledReminder::create([
            'user_id' => $student->id,
            'created_by' => null,
            'message' => "Намасте! Вчера вы оплатили курс «{$title}». "
                ."Первый урок уже ждет в личном кабинете: {$url}. "
                .'Начать можно с десяти минут — не обязательно проходить урок целиком.',
            'to_telegram' => true,
            'to_vk' => true,
            'to_email' => false,
            'scheduled_for' => now()->addDay()->setTime(11, 0),
        ]);

        ScheduledReminder::create([
            'user_id' => $student->id,
            'created_by' => null,
            'message' => "Намасте! Несколько дней назад вы оплатили курс «{$title}». "
                .'Если вы уже занимаетесь — просто проигнорируйте это сообщение. '
                ."Если еще не начали — первый урок ждет в кабинете: {$url}. "
                .'А если начать мешает что-то конкретное — напишите нам, поможем: https://t.me/rusamskrtam',
            'to_telegram' => true,
            'to_vk' => true,
            'to_email' => false,
            'scheduled_for' => now()->addDays(5)->setTime(11, 0),
        ]);
    }
}
