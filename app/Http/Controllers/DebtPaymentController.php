<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CuratorNotifier;
use App\Services\Payments\TochkaPaymentService;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use App\Services\PromiseAutoFulfiller;
use App\Services\StudentDebtsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Самообслуживание должника (self-service): студент сам гасит долг из личного
 * кабинета. Плоский долг «не продлил» без договорённости идёт штатным
 * CheckoutController (поблочно) ИЛИ сюда одним бандлом (payBundle). Рассрочка/
 * обещание — сюда (сумма зафиксирована куратором, не равна цене тарифа).
 *
 * Реальный платёж: pending → Точка → вебхук → Payment::processSuccessfulPayment
 * (доступ + BlockAccessMaterializer дорисует ключи мульти-блока) + PromiseAutoFulfiller
 * закроет покрытые обещания. Все платежи помечаются is_self_service=true.
 *
 * Phase 2: прана к рассрочке (лояльность/промокод по-прежнему нет — цена
 * фиксирована), кастомная частичная сумма, бандл плоского долга, перенос даты.
 */
class DebtPaymentController extends Controller
{
    /** Сколько минут держим незавершённый self-service заказ «занятым» (анти-дубль). */
    private const PENDING_HOLD_MINUTES = 30;

    /** На сколько дней вперёд студент может перенести дату обещания (один раз). */
    private const RESCHEDULE_MAX_DAYS = 14;

    public function __construct(
        private readonly StudentDebtsService $debts,
        private readonly PranaService $prana,
        private readonly PromiseAutoFulfiller $fulfiller,
    ) {}

    /** Оплатить одно обещание (одиночное или конкретный взнос рассрочки). */
    public function payPromise(PaymentPromise $promise, Request $request): RedirectResponse
    {
        $user = auth()->user();

        if ($promise->user_id !== $user->id) {
            abort(403);
        }

        if (! $promise->isUnmet()) {
            return $this->backToDebts('error', 'Это обещание уже закрыто или отменено.');
        }

        $debt = $this->debtRow($user, (int) $promise->course_id);
        $amount = $promise->amount !== null
            ? (float) $promise->amount
            : ($debt?->debt_amount !== null ? (float) $debt->debt_amount : 0.0);

        // Сколько блоков открыть = сколько взносов покрывает эта сумма (тем же
        // greedy-счётчиком, что закроет обещания на вебхуке).
        $covered = $this->fulfiller->coveredPromises($promise, $amount);

        // Приоритет: блоки, которые куратор уже указал «Открыть доступ под
        // обещание» (conditional Payments). Иначе self-service угадывал full.
        $keyAndBlocks = $this->scopeFromConditionalGrants($promise);

        return $this->startCheckout(
            user: $user,
            courseId: (int) $promise->course_id,
            amount: $amount,
            leadPromiseId: $promise->id,
            blocksToOpen: max(1, $covered->count()),
            closesFinalPromise: $this->coversFinalPromise($promise, $covered),
            pranaRequested: (int) $request->input('prana_amount', 0),
            keyAndBlocksOverride: $keyAndBlocks,
        );
    }

    /**
     * Погасить остаток графика по курсу. Без `amount` — весь остаток; с `amount`
     * — кастомная частичная сумма в диапазоне [ближайший взнос, весь остаток]
     * (greedy-закрытие ранних обещаний делает PromiseAutoFulfiller).
     */
    public function payAll(Course $course, Request $request): RedirectResponse
    {
        $user = auth()->user();

        $unmet = PaymentPromise::query()
            ->forPair($user->id, $course->id)
            ->whereIn('status', [PaymentPromise::STATUS_ACTIVE, PaymentPromise::STATUS_EXPIRED])
            ->orderBy('promised_at')
            ->get();

        if ($unmet->isEmpty()) {
            return $this->backToDebts('error', 'По этому курсу нет непогашенных договорённостей.');
        }

        $debt = $this->debtRow($user, (int) $course->id);
        $planRemaining = (float) $unmet->sum(fn (PaymentPromise $p) => (float) ($p->amount ?? 0));
        $remaining = $planRemaining > 0
            ? $planRemaining
            : ($debt?->debt_amount !== null ? (float) $debt->debt_amount : 0.0);

        // Ближайший взнос — нижняя граница кастомной частичной оплаты.
        $nextInstalment = (float) ($unmet->first()->amount ?? $remaining);

        $amount = $remaining;
        if ($request->filled('amount')) {
            $custom = (float) $request->input('amount');
            if ($custom < $nextInstalment - 0.01 || $custom > $remaining + 0.01) {
                return $this->backToDebts('error', 'Сумма должна быть не меньше ближайшего взноса и не больше остатка по графику.');
            }
            $amount = $custom;
        }

        $lead = $unmet->first();
        $covered = $this->fulfiller->coveredPromises($lead, $amount);

        return $this->startCheckout(
            user: $user,
            courseId: (int) $course->id,
            amount: $amount,
            leadPromiseId: (int) $lead->id,
            blocksToOpen: max(1, $covered->count()),
            closesFinalPromise: $this->coversFinalPromise($lead, $covered),
            pranaRequested: (int) $request->input('prana_amount', 0),
        );
    }

    /**
     * Бандл: погасить весь плоский многоблочный долг «не продлил» одним платежом
     * (без договорённости). Доступ ко всем блокам откроется через
     * BlockAccessMaterializer по диапазону.
     */
    public function payBundle(Course $course, Request $request): RedirectResponse
    {
        $user = auth()->user();
        $debt = $this->debtRow($user, (int) $course->id);

        if ($debt === null || ! empty($debt->has_arrangement)) {
            return $this->backToDebts('error', 'Бандл доступен только для долга без договорённости.');
        }

        $blocks = array_values(array_map('intval', $debt->debt_block_numbers ?? []));
        if (count($blocks) < 2) {
            return $this->backToDebts('error', 'Бандл применим к долгу из нескольких блоков.');
        }

        // Цена бандла = сумма итоговых цен тарифов оплачиваемых блоков (лояльность
        // применяется, как в обычном чекауте). Блоки без тарифа не бандлятся.
        $amount = 0.0;
        $unpriced = [];
        foreach ($blocks as $n) {
            $tariff = Tariff::query()
                ->where('course_id', $course->id)
                ->where('is_active', true)
                ->where('type', 'block')
                ->where('block_number', $n)
                ->whereNull('block_half')
                ->first();
            if ($tariff === null) {
                $unpriced[] = $n;

                continue;
            }
            $amount += (float) $tariff->calculateFinalPriceForUser($user);
        }

        if (! empty($unpriced)) {
            return $this->backToDebts('error', 'Не для всех блоков есть тариф — оформите оплату через куратора.');
        }

        // Fallback-бандл покрывает ВЕСЬ плоский долг сразу → открываем все его блоки
        // (closesFinalPromise=true расширяет диапазон до конца неоплаченного).
        return $this->startCheckout(
            user: $user,
            courseId: (int) $course->id,
            amount: $amount,
            leadPromiseId: null,
            blocksToOpen: max(1, count($blocks)),
            closesFinalPromise: true,
            pranaRequested: (int) $request->input('prana_amount', 0),
        );
    }

    /** Перенести дату ближайшего обещания вперёд (один раз, в пределах лимита). */
    public function reschedule(PaymentPromise $promise, Request $request): RedirectResponse
    {
        $user = auth()->user();

        if ($promise->user_id !== $user->id) {
            abort(403);
        }

        if (! $promise->isUnmet()) {
            return $this->backToDebts('error', 'Перенести можно только активное обещание.');
        }

        if ($promise->student_rescheduled_at !== null) {
            return $this->backToDebts('error', 'Вы уже переносили дату по этой договорённости. Дальнейшие изменения — через куратора.');
        }

        $request->validate(['new_date' => 'required|date']);
        $newDate = Carbon::parse($request->input('new_date'))->startOfDay();
        $today = now()->startOfDay();
        $maxDate = $today->copy()->addDays(self::RESCHEDULE_MAX_DAYS);
        $original = $promise->promised_at?->copy()->startOfDay() ?? $today;

        if ($newDate->lte($original)) {
            return $this->backToDebts('error', 'Новая дата должна быть позже текущей.');
        }
        if ($newDate->gt($maxDate)) {
            return $this->backToDebts('error', 'Перенести можно не более чем на '.self::RESCHEDULE_MAX_DAYS.' дней вперёд.');
        }

        $promise->forceFill([
            'promised_at' => $newDate,
            'student_rescheduled_at' => now(),
            // Возвращаем в active, если демон уже пометил expired — дата снова в будущем.
            'status' => PaymentPromise::STATUS_ACTIVE,
        ])->save();

        app(CuratorNotifier::class)->promiseRescheduledByStudent($promise);

        return $this->backToDebts('success', 'Дата оплаты перенесена на '.$newDate->format('d.m.Y').'. Куратор уведомлён.');
    }

    /**
     * Общая часть: (гард дубля) → создать pending-платёж (в транзакции, со
     * списанием праны) → увести в Точку ПОСЛЕ commit (сеть не держит row-lock).
     */
    /**
     * @param  array{0: string, 1: int|null, 2: int|null}|null  $keyAndBlocksOverride
     *                                                                                 [tariff, start, end] из conditional-гранта
     */
    private function startCheckout(
        User $user,
        int $courseId,
        float $amount,
        ?int $leadPromiseId,
        int $blocksToOpen,
        bool $closesFinalPromise,
        int $pranaRequested = 0,
        ?array $keyAndBlocksOverride = null,
    ): RedirectResponse {
        if ($amount <= 0) {
            return $this->backToDebts('error', 'Не удалось определить сумму к оплате. Обратитесь к куратору.');
        }

        // Анти-дубль: свежий незавершённый self-service заказ по этому курсу уже
        // висит (двойной клик / back) → не создаём второй payment-link, иначе
        // студент может оплатить долг дважды.
        $hasRecentPending = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('status', 'pending')
            ->where('is_self_service', true)
            ->where('created_at', '>=', now()->subMinutes(self::PENDING_HOLD_MINUTES))
            ->exists();

        if ($hasRecentPending) {
            return $this->backToDebts('error', 'У вас уже есть незавершённый заказ по этому курсу. Завершите оплату или подождите несколько минут, прежде чем начинать новый.');
        }

        $course = Course::with('blocks')->find($courseId);
        if (! $course) {
            return $this->backToDebts('error', 'Курс не найден.');
        }

        if ($keyAndBlocksOverride !== null) {
            [$tariffKey, $startBlock, $endBlock] = $keyAndBlocksOverride;
        } else {
            $unpaidBlocksAsc = $this->debts->unpaidBlockNumbers($user, $courseId);
            [$tariffKey, $startBlock, $endBlock] = $this->resolveKeyAndBlocks(
                $course,
                $unpaidBlocksAsc,
                $blocksToOpen,
                $closesFinalPromise,
            );
        }

        // Прана: студент может списать СВОЮ прану против суммы (лояльность/промокод
        // не применяем — цена фиксирована куратором). Максимум считаем на сервере,
        // клиенту не верим (зеркалит PaymentController::createPayment).
        $result = DB::transaction(function () use ($user, $courseId, $amount, $tariffKey, $startBlock, $endBlock, $leadPromiseId, $pranaRequested) {
            $finalAmount = $amount;
            $pranaToSpend = 0;

            if ($pranaRequested > 0 && PranaSettings::isActive()) {
                $maxSpend = $this->prana->maxSpendableForPrice($user, $finalAmount);
                $pranaToSpend = min($pranaRequested, $maxSpend);
                $rate = PranaSettings::rate();
                if ($rate > 0) {
                    $pranaToSpend = intdiv($pranaToSpend, $rate) * $rate;
                }
                if ($pranaToSpend > 0) {
                    $finalAmount = max(1.0, $finalAmount - $this->prana->pranaToRubles($pranaToSpend));
                } else {
                    $pranaToSpend = 0;
                }
            }

            $payment = Payment::create([
                'user_id' => $user->id,
                'course_id' => $courseId,
                'amount' => $finalAmount,
                'prana_spent' => $pranaToSpend,
                'tariff' => $tariffKey,
                'status' => 'pending',
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                'is_conditional' => false,
                'is_self_service' => true,
                'linked_promise_id' => $leadPromiseId,
            ]);

            if ($pranaToSpend > 0) {
                try {
                    $this->prana->spend($user, $pranaToSpend, 'spent_on_purchase', $payment);
                } catch (\RuntimeException $e) {
                    throw ValidationException::withMessages([
                        'prana_amount' => 'Не удалось списать прану — обновите страницу и попробуйте снова.',
                    ]);
                }
            }

            return [$payment, $finalAmount];
        });

        [$payment, $finalAmount] = $result;

        $courseName = $course->title ?? 'Курс';
        $purpose = 'Заказ №'.$payment->id.' | '.$courseName.' — погашение задолженности';

        try {
            $response = app(TochkaPaymentService::class)->createPaymentWithReceipt(
                user: $user,
                amount: (float) $finalAmount,
                purpose: $purpose,
                itemName: $courseName.' — погашение задолженности',
            );
        } catch (ConnectionException $e) {
            // failed → наблюдатель Payment вернёт списанную прану.
            $payment->update(['status' => 'failed']);
            Log::error('Tochka недоступна (self-service debt)', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return $this->backToDebts('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        }

        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $payment->update(['transaction_id' => $response['Data']['paymentLinkId']]);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $payment->update(['status' => 'failed']);
        Log::error('Ошибка Точка Эквайринг (self-service debt)', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
        ]);

        return $this->backToDebts('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * Ключ и диапазон блоков для платежа. Ключ определяется по РЕАЛЬНО
     * оплачиваемому объёму (сколько взносов покрыто → столько блоков снизу), а не
     * по всему долгу — иначе один взнос открывал бы весь курс (ключ 'full').
     *
     * @param  list<int>  $unpaidBlocksAsc  неоплаченные блоки курса по возрастанию
     * @return array{0: string, 1: int|null, 2: int|null} [tariffKey, startBlock, endBlock]
     */
    private function resolveKeyAndBlocks(
        Course $course,
        array $unpaidBlocksAsc,
        int $blocksToOpen,
        bool $closesFinalPromise,
    ): array {
        $blocks = array_values(array_map('intval', $unpaidBlocksAsc));
        sort($blocks);

        if (empty($blocks)) {
            // У курса нет блоков (напр. без разбивки) — легитимный full.
            return ['full', null, null];
        }

        // Открываем нижние N блоков (N = число покрытых взносов). Финальный платёж
        // гасит остаток графика → расширяем до конца, чтобы не осталось запертого
        // блока после полной оплаты.
        $slice = $closesFinalPromise
            ? $blocks
            : array_slice($blocks, 0, max(1, $blocksToOpen));

        $start = min($slice);
        $end = max($slice);
        $courseBlockCount = $course->blocks->count();

        // Оплачивается весь курс за раз → канонический 'full'. Иначе ключ по
        // стартовому блоку; остальные блоки диапазона откроет BlockAccessMaterializer.
        if ($courseBlockCount > 0 && count($slice) >= $courseBlockCount) {
            return ['full', $start, $end];
        }

        return ['block_'.$start, $start, $end];
    }

    /**
     * Scope доступа из conditional Payments «открыть доступ под обещание».
     * Куратор в админке явно указал full или block_N — self-service копирует
     * тот же объём, а не раздувает до full по списку unpaid.
     *
     * @return array{0: string, 1: int|null, 2: int|null}|null [tariff, start, end]
     */
    private function scopeFromConditionalGrants(PaymentPromise $promise): ?array
    {
        $grants = Payment::query()
            ->where('linked_promise_id', $promise->id)
            ->where('is_conditional', true)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->get(['tariff', 'start_block', 'end_block']);

        if ($grants->isEmpty()) {
            return null;
        }

        if ($grants->contains(fn (Payment $p) => $p->tariff === 'full')) {
            return ['full', null, null];
        }

        $blockNums = [];
        foreach ($grants as $grant) {
            if (is_string($grant->tariff) && preg_match('/^block_(\d+)/', $grant->tariff, $m)) {
                $blockNums[] = (int) $m[1];
            }
            $start = $grant->start_block !== null ? (int) $grant->start_block : null;
            $end = $grant->end_block !== null ? (int) $grant->end_block : null;
            if ($start !== null && $end !== null && $end >= $start) {
                for ($n = $start; $n <= $end; $n++) {
                    $blockNums[] = $n;
                }
            } elseif ($start !== null) {
                $blockNums[] = $start;
            } elseif ($end !== null) {
                $blockNums[] = $end;
            }
        }

        $blockNums = array_values(array_unique(array_filter($blockNums, fn (int $n) => $n > 0)));
        sort($blockNums);

        if ($blockNums === []) {
            return null;
        }

        $start = min($blockNums);
        $end = max($blockNums);

        // Один платёж на диапазон: materializer дорисует siblings block_N.
        return ['block_'.$start, $start, $end];
    }

    /**
     * Покрывает ли платёж ПОСЛЕДНЕЕ непогашенное обещание графика (значит долг
     * гасится полностью — открываем весь оставшийся диапазон блоков).
     *
     * Одиночная договорённость (нет installment_group_id) — всегда false:
     * один взнос = один (или grant-scope) блок, не silent full на весь курс.
     *
     * @param  Collection<int, PaymentPromise>  $covered
     */
    private function coversFinalPromise(PaymentPromise $lead, Collection $covered): bool
    {
        if ($lead->installment_group_id === null) {
            return false;
        }

        $last = $this->lastUnmetOfGroup($lead);

        return $last === null || $covered->contains(fn (PaymentPromise $p) => $p->id === $last->id);
    }

    /** Последнее (по дате) непогашенное обещание группы рассрочки, либо само lead. */
    private function lastUnmetOfGroup(PaymentPromise $lead): ?PaymentPromise
    {
        if ($lead->installment_group_id === null) {
            return $lead->isUnmet() ? $lead : null;
        }

        return PaymentPromise::query()
            ->inGroup($lead->installment_group_id)
            ->whereIn('status', [PaymentPromise::STATUS_ACTIVE, PaymentPromise::STATUS_EXPIRED])
            ->orderBy('promised_at')
            ->get()
            ->last();
    }

    private function debtRow(User $user, int $courseId): ?object
    {
        return $this->debts->forUser($user)->firstWhere('course_id', $courseId);
    }

    /**
     * Fail/success flash после self-service оплаты. Всегда кабинет + #debts:
     *  - Alpine восстанавливает вкладку «Мои долги» (dashboard activeTab);
     *  - не зависим от Referer (continue-learning CTA / deep link).
     * Fragment обязателен: plain back() сбрасывал вкладку в 'courses'.
     */
    private function backToDebts(string $key, string $message): RedirectResponse
    {
        return redirect()
            ->route('student.dashboard')
            ->withFragment('debts')
            ->with($key, $message);
    }
}
