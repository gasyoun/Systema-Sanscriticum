<?php


namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Tariff;
use App\Models\PromoCode;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function createPayment(Request $request)
{
    $rules = [
        'tariff_id' => 'required|exists:tariffs,id',
    ];

    if (!auth()->check()) {
        $rules['name']  = 'required|string|max:255';
        $rules['email'] = 'required|email|max:255';
    }

    $request->validate($rules);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {

        // 2. ПОЛУЧАЕМ ИЛИ СОЗДАЕМ ПОЛЬЗОВАТЕЛЯ
        if (auth()->check()) {
            $user = auth()->user();
        } else {
            $existingUser = User::where('email', $request->input('email'))->first();

            if ($existingUser) {
                $user = $existingUser;
            } else {
                $user = User::create([
                    'email'    => $request->input('email'),
                    'name'     => $request->input('name'),
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

        // --- ОПРЕДЕЛЯЕМ КЛЮЧ ДЛЯ ДОСТУПА ---
        $tariffKey = $tariff->type;

        if ($tariff->type === 'block') {
            $tariffKey = 'block_' . $tariff->block_number;
        }

        // 4. СОЗДАЕМ ПЛАТЕЖ
        $payment = Payment::create([
            'user_id'   => $user->id,
            'course_id' => $tariff->course->id ?? null,
            'amount'    => $finalPrice,
            'tariff'    => $tariffKey,
            'status'    => 'pending',
        ]);

        // 5. ИНКРЕМЕНТИРУЕМ ПРОМОКОД
        if ($promo) {
            $promo->increment('used_count');
            session()->forget('promo_code');
        }

        // 6. ЕСЛИ ЦЕНА 0
        if ($finalPrice == 0) {
            $payment->update(['status' => 'paid']);

            if (!auth()->check()) {
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
                    'customerCode'    => config('services.tochka.customer_code'),
                    'amount'          => round((float) $finalPrice, 2),
                    'purpose'         => 'Заказ №' . $payment->id . ' | ' . ($tariff->course->title ?? 'Курс') . ' - ' . $tariff->title,
                    'paymentMode'     => ['sbp', 'card'],
                    'redirectUrl'     => route('payment.success'),
                    'failRedirectUrl' => route('payment.fail'),
                ]
            ]);

        // 8. ОБРАБАТЫВАЕМ ОТВЕТ
        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $payment->update([
                'transaction_id' => $response['Data']['paymentLinkId']
            ]);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $payment->update(['status' => 'failed']);

        Log::error('Ошибка Точка Эквайринг', [
            'status' => $response->status(),
            'body'   => $response->json()
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    });
}

    public function success(Request $request)
    {
        if (!auth()->check()) {
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