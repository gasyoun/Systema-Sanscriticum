<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class Payment extends Model
{
    use HasFactory;

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
        'status',
        'transaction_id',
        // --- НОВЫЕ ПОЛЯ: Для поблочной оплаты ---
        'start_block',
        'end_block',
        // Ручной override месяца начисления ЗП (YYYY-MM); null = авто по блокам.
        'salary_recognition_month',
        // --- Conditional access под обещание/рассрочку ---
        'is_conditional',
        'linked_promise_id',
        // Дата платежа задаётся вручную при создании из админки.
        'created_at',
    ];

    protected $casts = [
        'is_conditional' => 'boolean',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'foreign_amount' => 'decimal:2',
        'referral_credit_applied' => 'decimal:2',
        'deposit_consumed_at' => 'datetime',
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

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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

    // ==========================================
    // АВТОМАТИЗАЦИЯ ПРИ СОЗДАНИИ ИЛИ ИЗМЕНЕНИИ
    // ==========================================
    protected static function booted()
    {
        // 1. Срабатывает при СОЗДАНИИ нового платежа
        static::created(function (Payment $payment) {
            // Ловим и 'success', и 'paid' (в зависимости от того, как сохраняет админка)
            if (in_array($payment->status, self::PAID_STATUSES, true)) {
                self::fireOnPaid($payment);
            }
        });

        // 2. Срабатывает при ИЗМЕНЕНИИ существующего платежа
        static::updated(function (Payment $payment) {
            if ($payment->isDirty('status') && in_array($payment->status, self::PAID_STATUSES, true)) {
                self::fireOnPaid($payment);
            }

            // Откатываем списанную прану и зачтённый реферальный кредит, если оплата сорвалась.
            // ВАЖНО: канон статуса в payments — 'canceled' (одно L; см. миграцию и админку
            // PaymentResource). 'cancelled' оставлен оборонительно для чужих написаний.
            if ($payment->isDirty('status') && in_array($payment->status, ['failed', 'canceled', 'cancelled'], true)) {
                $payment->refundPranaIfSpent();
                $payment->refundReferralCreditIfApplied();
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
        // Системный расход / возврат и выплата ЗП преподавателю — только
        // бухгалтерская строка. Никакого доступа, писем, праны, Telegram.
        if ($payment->isExpense() || $payment->isSalaryPayout()) {
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

        $payment->processSuccessfulPayment();

        // Conditional access под обещание — реальных денег нет,
        // депозит гасить не за что.
        if (! $payment->is_conditional) {
            $payment->consumeDepositsForCourse();
            $payment->reconcileConditionalGrants();
        }
    }

    // ==========================================
    // ГЛАВНЫЙ МЕТОД: ЗАПУСКАЕТ ВСЕ ПРОЦЕССЫ
    // ==========================================
    public function processSuccessfulPayment()
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            $this->grantAccess();
            $this->enrollInCourse();

            // Для conditional Payment (доступ под обещание) пропускаем
            // welcome-email и начисление праны — деньги не пришли,
            // не дарим бонусы и не флудим письмами.
            if (! $this->is_conditional) {
                $this->sendWelcomeEmailIfNeeded();
                $this->sendCourseWelcomeEmailIfFirstForCourse();
                $this->awardPranaForPurchase();
                // Засчитываем использование промокода только по подтверждённой
                // оплате (раньше инкремент стоял на создании pending, из-за чего
                // брошенные чекауты исчерпывали usage_limit).
                $this->promoCode?->markRedeemed();
            }
        });

        // Уведомление кураторам — только по реальным (не conditional) оплатам.
        // Conditional-доступ под обещание уведомляется в ConditionalAccessGranter.
        if (! $this->is_conditional) {
            app(\App\Services\CuratorNotifier::class)->paymentPaid($this);
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

        \App\Jobs\SendTelegramMessageJob::dispatch($this->user_id, $text);
    }

    // ==========================================
    // ДЕПОЗИТ (бронь курса) — отдельный путь
    // ==========================================
    public function processDeposit(): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            // НЕ вызываем grantAccess: депозит не открывает платные уроки.
            // Открытые уроки уже доступны любому залогиненному пользователю.
            $this->sendWelcomeEmailIfNeeded();

            $this->lead?->markConverted();
        });

        // Уведомление кураторам о брони (депозите).
        app(\App\Services\CuratorNotifier::class)->depositReceived($this);

        if (! $this->user_id) {
            return;
        }

        // E-mail-подтверждение брони (со ссылкой на чат курса). Telegram есть не
        // у всех — без письма повторные студенты не получали бы по брони ничего.
        if ($this->user && $this->user->email && $this->course) {
            \Illuminate\Support\Facades\Mail::to($this->user->email)
                ->send(new \App\Mail\DepositReceivedMail($this->user, $this->course));
        }

        $courseName = $this->course->title ?? 'курс';
        $url = url('/login');

        $text = "📌 <b>Бронь курса принята</b>\n\n";
        $text .= "Намасте! Мы получили предоплату за курс <b>«{$courseName}»</b>. ";
        $text .= 'Сумма зачтётся при оплате полного тарифа.';
        $text .= "\n\n<a href='{$url}'>Личный кабинет</a>";

        \App\Jobs\SendTelegramMessageJob::dispatch($this->user_id, $text);
    }

    // ==========================================
    // ПРОБНОЕ ЗАНЯТИЕ — отдельный путь
    // ==========================================
    public function processTrial(): void
    {
        $lessonId = $this->course?->trial_lesson_id;

        \Illuminate\Support\Facades\DB::transaction(function () use ($lessonId) {
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
            $this->lead?->markConverted();
        });

        app(\App\Services\CuratorNotifier::class)->depositReceived($this);

        if (! $this->user_id) {
            return;
        }

        // Данные живого занятия (дата + Zoom) — из события расписания курса.
        $schedule = $this->course?->trialSchedule;
        $zoomLink = $schedule?->link;
        $startsAt = $schedule?->start;

        // Письмо со ссылкой на Zoom (если есть email и пользователь, и сама запись расписания).
        if ($this->user && $this->user->email) {
            \Illuminate\Support\Facades\Mail::to($this->user->email)
                ->send(new \App\Mail\TrialZoomLinkMail($this->user, $this->course, $zoomLink, $startsAt));
        }

        $courseName = $this->course->title ?? 'курс';
        $url = url('/login');

        $text = "🎟 <b>Пробное занятие оплачено</b>\n\n";
        $text .= "Намасте! Вы записаны на живое занятие курса <b>«{$courseName}»</b>";
        if ($startsAt) {
            $text .= ' — '.$startsAt->translatedFormat('d F, H:i').' (МСК)';
        }
        $text .= ".\n";
        if ($zoomLink) {
            $text .= "\n🔗 <a href='{$zoomLink}'>Подключиться к Zoom</a>\n";
        }
        $text .= 'Сумма зачтётся при оплате полного тарифа.';
        $text .= "\n\n<a href='{$url}'>Личный кабинет</a>";

        \App\Jobs\SendTelegramMessageJob::dispatch($this->user_id, $text);
    }

    /**
     * Гасит все ранее оплаченные и ещё не зачтённые депозиты по тому же
     * курсу, что и текущий «реальный» платёж. updateQuietly — чтобы не
     * перезапустить booted-хуки и не зациклить обсёрвер.
     */
    public function consumeDepositsForCourse(): void
    {
        if (! $this->course_id) {
            return;
        }

        self::query()
            ->where('user_id', $this->user_id)
            ->where('course_id', $this->course_id)
            ->unconsumedDeposits()
            ->get()
            ->each(fn (self $deposit) => $deposit->updateQuietly([
                'deposit_consumed_at' => now(),
            ]));
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
            Log::warning(
                "grantAccess: у курса '{$course->title}' (id={$course->id}) ".
                'нет привязанных групп. Проверьте вкладку «Группы» в админке курса.'
            );

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
        app(\App\Services\Prana\PranaService::class)
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

        app(\App\Services\Prana\PranaService::class)->refund(
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
            \Illuminate\Support\Facades\Log::error('Студент не найден для платежа ID: '.$this->id);

            return;
        }

        // Админам welcome-письмо с генерируемым паролем не нужно —
        // у них уже есть свой пароль, перезапись его сломает доступ.
        if ($student->is_admin) {
            return;
        }

        // Считаем успешные оплаты
        $paymentsCount = $student->payments()->paid()->count();

        // Пишем в лог, сколько оплат нашла система
        \Illuminate\Support\Facades\Log::info("Попытка отправки письма. Студент: {$student->email}. Найдено успешных оплат: {$paymentsCount}");

        // Если это первая оплата
        if ($paymentsCount === 1) {
            \Illuminate\Support\Facades\Log::info("Генерируем пароль и отправляем письмо студенту: {$student->email}");

            $newPassword = \Illuminate\Support\Str::random(8);
            $student->password = \Illuminate\Support\Facades\Hash::make($newPassword);
            $student->save();

            \Illuminate\Support\Facades\Mail::to($student->email)->send(new \App\Mail\StudentWelcomeMail($student, $newPassword));

            \Illuminate\Support\Facades\Log::info('Письмо успешно передано в почтовик!');
        } else {
            \Illuminate\Support\Facades\Log::warning("Письмо НЕ отправлено, так как это не первая оплата (счетчик: {$paymentsCount})");
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
            \Illuminate\Support\Facades\Mail::to($student->email)
                ->send(new \App\Mail\CourseWelcomeMail($student, $this->course));
        }
    }
}
