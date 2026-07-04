<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Services\Payments\TochkaPaymentService;
use App\Services\StudentDebtsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Самообслуживание должника (self-service): студент сам платит по согласованной
 * договорённости/рассрочке из личного кабинета. Плоский долг «не продлил» идёт
 * штатным CheckoutController — сюда попадает только promise-оплата, где сумма
 * зафиксирована куратором (часто со скидкой) и не равна цене тарифа.
 *
 * Реальный платёж создаётся по образцу PromiseFulfillment/PaymentController:
 * pending → Точка → вебхук переведёт в paid → Payment::processSuccessfulPayment
 * выдаст доступ, а PromiseAutoFulfiller закроет привязанное обещание. Поле
 * linked_promise_id при is_conditional=false — маркер self-service оплаты.
 *
 * Промокод/прана к согласованной рассрочке в Phase 1 не применяются намеренно.
 */
class DebtPaymentController extends Controller
{
    /** Сколько минут держим незавершённый self-service заказ «занятым» (анти-дубль). */
    private const PENDING_HOLD_MINUTES = 30;

    public function __construct(
        private readonly StudentDebtsService $debts,
    ) {}

    /** Оплатить одно обещание (одиночное или очередной платёж рассрочки). */
    public function payPromise(PaymentPromise $promise): RedirectResponse
    {
        $user = auth()->user();

        if ($promise->user_id !== $user->id) {
            abort(403);
        }

        if (! $promise->isUnmet()) {
            return back()->with('error', 'Это обещание уже закрыто или отменено.');
        }

        $debt = $this->debtRow($user, (int) $promise->course_id);
        $amount = $promise->amount !== null
            ? (float) $promise->amount
            : ($debt?->debt_amount !== null ? (float) $debt->debt_amount : 0.0);

        return $this->startCheckout(
            user: $user,
            courseId: (int) $promise->course_id,
            amount: $amount,
            leadPromiseId: $promise->id,
            debt: $debt,
        );
    }

    /** Погасить весь остаток графика по курсу одним платежом. */
    public function payAll(Course $course): RedirectResponse
    {
        $user = auth()->user();

        $unmet = PaymentPromise::query()
            ->forPair($user->id, $course->id)
            ->whereIn('status', [PaymentPromise::STATUS_ACTIVE, PaymentPromise::STATUS_EXPIRED])
            ->orderBy('promised_at')
            ->get();

        if ($unmet->isEmpty()) {
            return back()->with('error', 'По этому курсу нет непогашенных договорённостей.');
        }

        $debt = $this->debtRow($user, (int) $course->id);
        // Остаток по графику; если суммы обещаний не заданы — берём расчётный долг.
        $planRemaining = (float) $unmet->sum(fn (PaymentPromise $p) => (float) ($p->amount ?? 0));
        $amount = $planRemaining > 0
            ? $planRemaining
            : ($debt?->debt_amount !== null ? (float) $debt->debt_amount : 0.0);

        return $this->startCheckout(
            user: $user,
            courseId: (int) $course->id,
            amount: $amount,
            leadPromiseId: (int) $unmet->first()->id,
            debt: $debt,
        );
    }

    /**
     * Общая часть: создать pending-платёж (в транзакции) и увести в Точку
     * (после commit — сеть держать row-lock нельзя, как в PaymentController).
     */
    private function startCheckout(
        \App\Models\User $user,
        int $courseId,
        float $amount,
        int $leadPromiseId,
        ?object $debt,
    ): RedirectResponse {
        if ($amount <= 0) {
            return back()->with('error', 'Не удалось определить сумму к оплате. Обратитесь к куратору.');
        }

        $course = Course::with('blocks')->find($courseId);
        if (! $course) {
            return back()->with('error', 'Курс не найден.');
        }

        // Блоки долга → диапазон/ключ. Зеркалим PromiseFulfillment: tariff по
        // стартовому блоку (или 'full', если долг = весь курс), start/end для
        // отчётности. Так self-service открывает доступ ровно как ручное
        // «Подтвердить оплату» куратора — без новой семантики доступа.
        [$tariffKey, $startBlock, $endBlock] = $this->resolveKeyAndBlocks($course, $debt);

        $payment = DB::transaction(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $courseId,
            'amount' => $amount,
            'tariff' => $tariffKey,
            'status' => 'pending',
            'start_block' => $startBlock,
            'end_block' => $endBlock,
            'is_conditional' => false,
            'linked_promise_id' => $leadPromiseId,
        ]));

        $courseName = $course->title ?? 'Курс';
        $purpose = 'Заказ №'.$payment->id.' | '.$courseName.' — погашение задолженности';

        try {
            $response = app(TochkaPaymentService::class)->createPaymentWithReceipt(
                user: $user,
                amount: $amount,
                purpose: $purpose,
                itemName: $courseName.' — погашение задолженности',
            );
        } catch (ConnectionException $e) {
            $payment->update(['status' => 'failed']);
            Log::error('Tochka недоступна (self-service debt)', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
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

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * @return array{0: string, 1: int|null, 2: int|null} [tariffKey, startBlock, endBlock]
     */
    private function resolveKeyAndBlocks(Course $course, ?object $debt): array
    {
        $blocks = $debt?->debt_block_numbers ?? [];
        $blocks = array_values(array_map('intval', $blocks));

        if (empty($blocks)) {
            // Нет разложения по блокам (курс без актуального блока / чистая
            // договорённость) — открываем весь курс: студент гасит весь долг.
            return ['full', null, null];
        }

        $start = min($blocks);
        $end = max($blocks);
        $courseBlockCount = $course->blocks->count();

        // Долг = весь курс → 'full' (один ключ открывает всё). Иначе ключ по
        // стартовому блоку, как в PromiseFulfillment.
        if ($courseBlockCount > 0 && count($blocks) >= $courseBlockCount) {
            return ['full', $start, $end];
        }

        return ['block_'.$start, $start, $end];
    }

    private function debtRow(\App\Models\User $user, int $courseId): ?object
    {
        return $this->debts->forUser($user)->firstWhere('course_id', $courseId);
    }
}
