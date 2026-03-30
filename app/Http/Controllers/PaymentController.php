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
        // 1. ВАЛИДАЦИЯ (Динамическая)
        $rules = [
            'tariff_id' => 'required|exists:tariffs,id',
        ];

        // Если гость, обязательно требуем имя и email
        if (!auth()->check()) {
            $rules['name']  = 'required|string|max:255';
            $rules['email'] = 'required|email|max:255';
        }

        $request->validate($rules);

        // 2. ПОЛУЧАЕМ ИЛИ СОЗДАЕМ ПОЛЬЗОВАТЕЛЯ
        if (auth()->check()) {
            $user = auth()->user();
        } else {
            // Ищем по email или создаем нового
            $user = \App\Models\User::firstOrCreate(
                ['email' => $request->input('email')],
                [
                    'name'     => $request->input('name'),
                    // Временный пароль. После оплаты модель Payment сгенерирует новый и отправит на почту!
                    'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(12)),
                ]
            );

            // Авторизуем его "под капотом", чтобы дальше он шел как свой
            auth()->login($user); 
        }

        $tariff = \App\Models\Tariff::with('course')->findOrFail($request->input('tariff_id'));

        // 3. СЧИТАЕМ ИТОГОВУЮ ЦЕНУ (с учетом лояльности из твоей модели)
        $finalPrice = $tariff->calculateFinalPriceForUser($user);

        // Применяем промокод из сессии
        if (session()->has('promo_code')) {
            $promo = \App\Models\PromoCode::where('code', session('promo_code'))->first();
            if ($promo && $promo->isValid()) {
                $finalPrice = $promo->calculateDiscountedPrice($finalPrice);
            }
        }

        $finalPrice = max(0, $finalPrice);

        // --- ОПРЕДЕЛЯЕМ ПРАВИЛЬНЫЙ КЛЮЧ ДЛЯ ДОСТУПА ---
        $tariffKey = $tariff->type; // по умолчанию берем type (например, 'full')
        
        if ($tariff->type === 'block') {
            // Если это блок, то приклеиваем к нему номер (получится 'block_1')
            // ВАЖНО: убедись, что в модели Tariff колонка с номером называется block_number
            $tariffKey = 'block_' . $tariff->block_number; 
        }
        // ----------------------------------------------

        // --- ОПРЕДЕЛЯЕМ ПРАВИЛЬНЫЙ КЛЮЧ ДЛЯ ДОСТУПА ---
        $tariffKey = $tariff->type; // по умолчанию берем type (например, 'full')
        
        if ($tariff->type === 'block') {
            // Если это блок, то приклеиваем к нему номер (получится 'block_1')
            // ВАЖНО: убедись, что в модели Tariff колонка с номером называется block_number
            $tariffKey = 'block_' . $tariff->block_number; 
        }
        // ----------------------------------------------

        // 4. СОЗДАЕМ ЧЕРНОВИК ПЛАТЕЖА В БАЗЕ ДАННЫХ
        $payment = \App\Models\Payment::create([
            'user_id'   => $user->id,
            'course_id' => $tariff->course->id ?? null,
            'amount'    => $finalPrice,
            
            // Записываем наш правильно собранный ключ!
            'tariff'    => $tariffKey, 
            
            'status'    => 'pending', 
        ]);

        // 5. ЕСЛИ ЦЕНА 0 (100% скидка) внутри метода createPayment
        if ($finalPrice == 0) {
            // ВОТ ЗДЕСЬ МЫ СТАВИМ PAID, так как банк нам не нужен
            $payment->update(['status' => 'paid']); 
            session()->forget('promo_code');
            return redirect()->route('student.dashboard')->with('success', 'Доступ к курсу успешно открыт!');
        }

        // 6. ОТПРАВЛЯЕМ ЗАПРОС В ТОЧКУ
        $response = \Illuminate\Support\Facades\Http::withToken(config('services.tochka.token'))
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->post('https://enter.tochka.com/uapi/acquiring/v1.0/payments', [
                'Data' => [
                    'customerCode'    => config('services.tochka.customer_code'),
                    'amount'          => round((float) $finalPrice, 2),
                    
                    // --- ВАЖНОЕ ИЗМЕНЕНИЕ ЗДЕСЬ ---
                    // Добавляем номер заказа в назначение платежа, чтобы вебхук смог его найти
                    'purpose'         => 'Заказ №' . $payment->id . ' | ' . ($tariff->course->title ?? 'Курс') . ' - ' . $tariff->title,
                    // -------------------------------
                    
                    'paymentMode'     => ['sbp', 'card'],
                    'redirectUrl'     => route('payment.success'),
                    'failRedirectUrl' => route('payment.fail'),
                ]
            ]);

        // 7. ОБРАБАТЫВАЕМ ОТВЕТ БАНКА
        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            
            // Запоминаем ID транзакции для вебхука
            $payment->update([
                'transaction_id' => $response['Data']['paymentLinkId']
            ]);

            // Перенаправляем на оплату
            return redirect()->away($response['Data']['paymentLink']);
        }

        // Если банк не дал ссылку (например, эквайринг еще отключен)
        $payment->update(['status' => 'failed']);
        
        \Illuminate\Support\Facades\Log::error('Ошибка Точка Эквайринг', [
            'status' => $response->status(),
            'body'   => $response->json()
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }
    
    // Метод: Сюда Точка возвращает клиента ПОСЛЕ оплаты
    public function success(Request $request)
    {
        // Мы НЕ меняем статус здесь! Мы просто отправляем в кабинет.
        // Вебхук поменяет статус на 'paid' сам, в фоновом режиме.
        return redirect()->route('student.dashboard')->with('success', 'Оплата успешно завершена! Доступ к материалам откроется в течение пары минут, как только банк подтвердит операцию.');
    }

    // Метод: Сюда Точка возвращает клиента при отмене или ошибке
    public function fail(Request $request)
    {
        // Если банк вернул ошибку, мы можем найти последний pending-платеж пользователя
        // и отменить его, чтобы он не висел мертвым грузом (по желанию).
        if (auth()->check()) {
            $lastPayment = \App\Models\Payment::where('user_id', auth()->id())
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