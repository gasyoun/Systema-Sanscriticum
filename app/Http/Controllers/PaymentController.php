<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function createPayment(Request $request, PranaService $prana)
    {
        $rules = [
            'tariff_id' => 'required|exists:tariffs,id',
            'prana_amount' => 'nullable|integer|min:0',
        ];

        if (! auth()->check()) {
            $rules['name'] = 'required|string|max:255';
            $rules['email'] = 'required|email|max:255';
        }

        $request->validate($rules);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $prana) {

            // 2. ПОЛУЧАЕМ ИЛИ СОЗДАЕМ ПОЛЬЗОВАТЕЛЯ
            if (auth()->check()) {
                $user = auth()->user();
            } else {
                $existingUser = User::where('email', $request->input('email'))->first();

                if ($existingUser) {
                    $user = $existingUser;
                } else {
                    $user = User::create([
                        'email' => $request->input('email'),
                        'name' => $request->input('name'),
                        'password' => Hash::make(Str::random(12)),
                    ]);
                    auth()->login($user);
                }
            }

            $tariff = Tariff::with('course')->findOrFail($request->input('tariff_id'));

            // 3. СЧИТАЕМ ИТОГОВУЮ ЦЕНУ
            $finalPrice = $tariff->calculateFinalPriceForUser($user);

            // Применяем промокод
            $promo = null;
            if (session()->has('promo_code')) {
                $promo = PromoCode::where('code', session('promo_code'))
                    ->lockForUpdate()
                    ->first();

                if ($promo && $promo->isValid()) {
                    $finalPrice = $promo->calculateDiscountedPrice($finalPrice);
                } else {
                    $promo = null;
                }
            }

            $finalPrice = max(0, $finalPrice);

            // --- ПРАНА: списание в счёт оплаты ---
            $pranaToSpend = (int) $request->input('prana_amount', 0);
            $pranaDiscountRubles = 0.0;

            if ($pranaToSpend > 0 && auth()->check()) {
                // Пересчитываем серверный максимум — игнорируем то, что прислал клиент.
                $maxSpend = $prana->maxSpendableForPrice($user, $finalPrice);
                $pranaToSpend = min($pranaToSpend, $maxSpend);

                // Снэпим к кратному rate — иначе клиент может прислать значение
                // вне step слайдера (например, 295 при rate=10) и получить дробную
                // скидку в payments.amount. maxSpendableForPrice уже возвращает
                // кратное rate, но min(295, 300) даёт 295.
                $rate = PranaSettings::rate();
                if ($rate > 0) {
                    $pranaToSpend = intdiv($pranaToSpend, $rate) * $rate;
                }

                if ($pranaToSpend > 0) {
                    $pranaDiscountRubles = $prana->pranaToRubles($pranaToSpend);
                    $finalPrice = max(1, $finalPrice - $pranaDiscountRubles);
                }
            } else {
                $pranaToSpend = 0;
            }

            // --- ОПРЕДЕЛЯЕМ КЛЮЧ ДЛЯ ДОСТУПА И НОМЕРА БЛОКОВ ---
            $tariffKey = $tariff->type;
            $startBlock = null;
            $endBlock = null;

            if ($tariff->type === 'block') {
                $tariffKey = 'block_'.$tariff->block_number;
                // 💡 Сохраняем номер блока — для отчётности в Google Sheets
                // и чтобы администратор видел, за какой блок платят
                $startBlock = $tariff->block_number;
                $endBlock = $tariff->block_number;
            }

            // 4. СОЗДАЕМ ПЛАТЕЖ
            $payment = Payment::create([
                'user_id' => $user->id,
                'course_id' => $tariff->course->id ?? null,
                'amount' => $finalPrice,
                'prana_spent' => $pranaToSpend,
                'tariff' => $tariffKey,
                'status' => 'pending',
                'start_block' => $startBlock,
                'end_block' => $endBlock,
            ]);

            // Списываем прану ровно сейчас, в той же транзакции — потом, если оплата
            // не пройдёт, наблюдатель Payment::updated вернёт её через refundPranaIfSpent().
            if ($pranaToSpend > 0) {
                try {
                    $prana->spend($user, $pranaToSpend, 'spent_on_purchase', $payment);
                } catch (\RuntimeException $e) {
                    // Race: вторая вкладка/двойной клик успели списать раньше. Не отдаём 500 —
                    // транзакция откатится по ValidationException, юзер вернётся к форме с ошибкой.
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'prana_amount' => 'Не удалось списать прану — обновите страницу и попробуйте снова.',
                    ]);
                }
            }

            // 5. ИНКРЕМЕНТИРУЕМ ПРОМОКОД
            if ($promo) {
                $promo->increment('used_count');
                session()->forget('promo_code');
            }

            // 6. ЕСЛИ ЦЕНА 0
            if ($finalPrice == 0) {
                $payment->update(['status' => 'paid']);

                if (! auth()->check()) {
                    return redirect()->route('login')
                        ->with('success', 'Доступ открыт! Войдите в аккаунт, чтобы начать обучение.');
                }

                return redirect()->route('student.dashboard')
                    ->with('success', 'Доступ к курсу успешно открыт!');
            }

            // 7. ОТПРАВЛЯЕМ ЗАПРОС В ТОЧКУ
            $response = Http::withToken(config('services.tochka.token'))
                ->withHeaders(['Accept' => 'application/json'])
                ->post('https://enter.tochka.com/uapi/acquiring/v1.0/payments', [
                    'Data' => [
                        'customerCode' => config('services.tochka.customer_code'),
                        'amount' => round((float) $finalPrice, 2),
                        'purpose' => 'Заказ №'.$payment->id.' | '.($tariff->course->title ?? 'Курс').' - '.$tariff->title,
                        'paymentMode' => ['sbp', 'card'],
                        'redirectUrl' => route('payment.success'),
                        'failRedirectUrl' => route('payment.fail'),
                    ],
                ]);

            // 8. ОБРАБАТЫВАЕМ ОТВЕТ
            if ($response->successful() && isset($response['Data']['paymentLink'])) {
                $payment->update([
                    'transaction_id' => $response['Data']['paymentLinkId'],
                ]);

                return redirect()->away($response['Data']['paymentLink']);
            }

            $payment->update(['status' => 'failed']);

            Log::error('Ошибка Точка Эквайринг', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        });
    }

    public function success(Request $request)
    {
        if (! auth()->check()) {
            return redirect()->route('login')
                ->with('success', 'Оплата прошла успешно! Войдите в аккаунт, чтобы начать обучение.');
        }

        return redirect()->route('student.dashboard')
            ->with('success', 'Оплата успешно завершена! Доступ откроется в течение пары минут.');
    }

    public function fail(Request $request)
    {
        if (auth()->check()) {
            $lastPayment = Payment::where('user_id', auth()->id())
                ->where('status', 'pending')
                ->latest()
                ->first();

            if ($lastPayment) {
                $lastPayment->update(['status' => 'failed']);
            }
        }

        return redirect('/')->with('error', 'Оплата была отменена или произошла ошибка. Вы можете попробовать снова.');
    }
}
