<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepositRequest;
use App\Models\Course;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\TochkaPaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DepositController extends Controller
{
    public function create(StoreDepositRequest $request, Course $course, TochkaPaymentService $tochka): RedirectResponse
    {
        $settings = MarketingSetting::cached();
        $amount = (float) $course->deposit_amount;

        // Бронь доступна только если глобальный тогл включён И у курса задана сумма.
        abort_unless(
            $settings?->deposit_enabled && $amount > 0,
            403,
            'Бронь для этого курса сейчас недоступна.'
        );

        // Резолв пользователя — вне транзакции, может бросить ValidationException
        // (например, гость указал email уже существующего аккаунта).
        $user = $this->resolveUser($request);

        // Только запись в БД — в транзакции. HTTP-вызов в Tochka делается ПОСЛЕ
        // commit, иначе медленный/упавший эквайринг держит row-lock на payments.
        $payment = DB::transaction(function () use ($user, $course, $amount): Payment {
            // Lead-матчинг по email — берём самого свежего непомеченного.
            // Помечается converted_at только при `paid`-вебхуке через PaymentObserver-цепочку
            // (см. Payment::processDeposit + Lead::markConverted), а не здесь, иначе
            // отвалившаяся оплата оставила бы лида ложно «сконвертированным».
            $lead = Lead::where('email', $user->email)
                ->whereNull('converted_at')
                ->latest()
                ->first();

            return Payment::create([
                'user_id' => $user->id,
                'lead_id' => $lead?->id,
                'course_id' => $course->id,
                'amount' => $amount,
                'tariff' => 'deposit',
                'status' => 'pending',
                'start_block' => null,
                'end_block' => null,
            ]);
        });

        // Purpose должен включать «Заказ №{id} | ...» — иначе вебхук Tochka
        // (см. WebhookController::handleTochkaWebhook, regex /Заказ №(\d+)/)
        // не найдёт платёж и депозит навсегда останется в pending.
        $purpose = 'Заказ №'.$payment->id.' | Бронь курса «'.$course->title.'» — предоплата';

        try {
            // Бронь — это предоплата. Эндпоинт payments_with_receipt Точки принимает
            // суженный набор: paymentMethod ∈ {full_payment, full_prepayment},
            // paymentObject ∈ {goods, service, work}. Для предоплаты услуги —
            // full_prepayment + service (значения prepayment/payment Точка отклоняет 400).
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: $amount,
                purpose: $purpose,
                itemName: 'Бронь курса «'.$course->title.'»',
                paymentMethod: 'full_prepayment',
                paymentObject: 'service',
            );
        } catch (ConnectionException $e) {
            $payment->update(['status' => 'failed']);

            Log::error('Tochka недоступна (deposit)', [
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

        Log::error('Ошибка Точка Эквайринг (deposit)', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * Возвращает пользователя для оформления депозита.
     *
     * Залогиненный — берём текущего. Гость с НОВЫМ email — создаём аккаунт и
     * логиним (риска takeover нет, владелец сам только что ввёл email).
     *
     * Гость, указавший СУЩЕСТВУЮЩИЙ email — отказ. Раньше здесь стоял
     * auth()->login($existing) без проверки пароля, что давало классический
     * account takeover: кто угодно мог залогиниться в чужой кабинет, указав
     * чужой email в публичной депозит-форме.
     */
    private function resolveUser(StoreDepositRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->input('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и оформите бронь оттуда.',
            ]);
        }

        $user = User::create([
            'email' => $request->input('email'),
            'name' => $request->input('name'),
            'password' => Hash::make(Str::random(12)),
        ]);

        auth()->login($user);

        return $user;
    }
}
