<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepositRequest;
use App\Models\Course;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DepositController extends Controller
{
    public function create(StoreDepositRequest $request, Course $course): RedirectResponse
    {
        $settings = MarketingSetting::cached();
        $amount = (float) $course->deposit_amount;

        // Бронь доступна только если глобальный тогл включён И у курса задана сумма.
        abort_unless(
            $settings?->deposit_enabled && $amount > 0,
            403,
            'Бронь для этого курса сейчас недоступна.'
        );

        return DB::transaction(function () use ($request, $course, $amount): RedirectResponse {
            $user = $this->resolveUser($request);

            // Lead-матчинг по email — берём самого свежего непомеченного.
            // Помечается converted_at только при `paid`-вебхуке через PaymentObserver-цепочку
            // (см. Payment::processDeposit + Lead::markConverted), а не здесь, иначе
            // отвалившаяся оплата оставила бы лида ложно «сконвертированным».
            $lead = Lead::where('email', $user->email)
                ->whereNull('converted_at')
                ->latest()
                ->first();

            $payment = Payment::create([
                'user_id' => $user->id,
                'lead_id' => $lead?->id,
                'course_id' => $course->id,
                'amount' => $amount,
                'tariff' => 'deposit',
                'status' => 'pending',
                'start_block' => null,
                'end_block' => null,
            ]);

            // Purpose должен включать «Заказ №{id} | ...» — иначе вебхук Tochka
            // (см. WebhookController::handleTochkaWebhook, regex /Заказ №(\d+)/)
            // не найдёт платёж и депозит навсегда останется в pending.
            $purpose = 'Заказ №'.$payment->id.' | Бронь курса «'.$course->title.'» — предоплата';

            try {
                $response = Http::withToken(config('services.tochka.token'))
                    ->withHeaders(['Accept' => 'application/json'])
                    ->post('https://enter.tochka.com/uapi/acquiring/v1.0/payments', [
                        'Data' => [
                            'customerCode' => config('services.tochka.customer_code'),
                            'amount' => round($amount, 2),
                            'purpose' => $purpose,
                            'paymentMode' => ['sbp', 'card'],
                            'redirectUrl' => route('payment.success'),
                            'failRedirectUrl' => route('payment.fail'),
                        ],
                    ]);
            } catch (ConnectionException $e) {
                // Сетевой сбой / TLS / DNS / timeout — payment остаётся, но в failed,
                // чтобы был след попытки. Без catch Guzzle бросает RequestException
                // наружу из DB::transaction → 500 и потерянная строка платежа.
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
                'body' => $response->json(),
            ]);

            return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        });
    }

    private function resolveUser(StoreDepositRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', $request->input('email'))->first();
        if ($existing) {
            auth()->login($existing);

            return $existing;
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
