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
        'amount',
        'prana_spent',
        'tariff',
        'deposit_consumed_at',
        'status',
        'transaction_id',
        // --- НОВЫЕ ПОЛЯ: Для поблочной оплаты ---
        'start_block',
        'end_block',
        // --- Conditional access под обещание/рассрочку ---
        'is_conditional',
        'linked_promise_id',
        // Дата платежа задаётся вручную при создании из админки.
        'created_at',
    ];

    protected $casts = [
        'is_conditional' => 'boolean',
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
            ->whereIn('status', ['paid', 'success']);
    }

    // ==========================================
    // АВТОМАТИЗАЦИЯ ПРИ СОЗДАНИИ ИЛИ ИЗМЕНЕНИИ
    // ==========================================
    protected static function booted()
    {
        // 1. Срабатывает при СОЗДАНИИ нового платежа
        static::created(function (Payment $payment) {
            // Ловим и 'success', и 'paid' (в зависимости от того, как сохраняет админка)
            if (in_array($payment->status, ['success', 'paid'], true)) {
                self::fireOnPaid($payment);
            }
        });

        // 2. Срабатывает при ИЗМЕНЕНИИ существующего платежа
        static::updated(function (Payment $payment) {
            if ($payment->isDirty('status') && in_array($payment->status, ['success', 'paid'], true)) {
                self::fireOnPaid($payment);
            }

            // Откатываем списанную прану, если оплата сорвалась.
            if ($payment->isDirty('status') && in_array($payment->status, ['failed', 'cancelled'], true)) {
                $payment->refundPranaIfSpent();
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
        // Системный расход / возврат — только бухгалтерская строка.
        // Никакого доступа, писем, праны, Telegram и уведомлений кураторам.
        if ($payment->isExpense()) {
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
                $this->awardPranaForPurchase();
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
        $paymentsCount = $student->payments()->whereIn('status', ['success', 'paid'])->count();

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
}
