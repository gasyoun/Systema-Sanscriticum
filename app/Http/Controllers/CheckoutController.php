<?php

namespace App\Http\Controllers;

use App\Models\Tariff;
use App\Models\LandingPage;
use App\Models\PromoCode; // Не забываем импортировать модель!
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function show(Tariff $tariff)
    {
        if (!$tariff->is_active) {
            abort(404, 'Тариф недоступен для покупки.');
        }

        $tariff->load('course');
        $page = $tariff->course ? LandingPage::where('slug', $tariff->course->slug)->first() : new LandingPage(['title' => 'Оформление заказа']);

        $user = auth()->user();
        
        // --- ДАННЫЕ ПРОГРАММЫ ЛОЯЛЬНОСТИ (ДЛЯ ВЫВОДА В ШАБЛОНЕ) ---
        $isLoyal = false;
        $loyaltyPercent = 0;
        
        if ($user) {
            $marketing = \App\Models\MarketingSetting::first();
            if ($marketing && $marketing->is_loyalty_active) {
                // Проверяем, есть ли у пользователя успешные оплаты
                $hasAnyPaid = \App\Models\Payment::where('user_id', $user->id)->where('status', 'paid')->exists();
                if ($hasAnyPaid) {
                    $isLoyal = true;
                    $loyaltyPercent = $marketing->loyalty_discount_percent;
                }
            }
        }

        // Базовая цена (уже с учетом скидки лояльности и скидки за прошлые покупки)
        $finalPrice = $tariff->calculateFinalPriceForUser($user);

        // --- МАГИЯ ПРОМОКОДОВ ---
        $appliedPromo = null;
        $discountAmount = 0;

        // Если в сессии есть промокод
        if (session()->has('promo_code')) {
            $promo = PromoCode::where('code', session('promo_code'))->first();
            
            // Проверяем, жив ли он еще (вдруг он истек, пока юзер думал)
            if ($promo && $promo->isValid()) {
                $appliedPromo = $promo;
                // Считаем новую цену от $finalPrice
                $priceWithPromo = $promo->calculateDiscountedPrice($finalPrice);
                $discountAmount = $finalPrice - $priceWithPromo;
                $finalPrice = $priceWithPromo;
            } else {
                // Код "протух" — выкидываем его из сессии
                session()->forget('promo_code');
            }
        }

        // ВАЖНО: Добавили isLoyal и loyaltyPercent в передачу шаблону!
        return view('checkout.show', compact('tariff', 'finalPrice', 'page', 'appliedPromo', 'discountAmount', 'isLoyal', 'loyaltyPercent'));
    }

    // Метод: Применить промокод
    public function applyPromo(Request $request, Tariff $tariff)
    {
        $request->validate(['code' => 'required|string']);

        $code = mb_strtoupper(trim($request->code));
        $promo = PromoCode::where('code', $code)->first();

        if (!$promo || !$promo->isValid()) {
            return back()->with('error', 'Промокод не найден или истек срок его действия.');
        }

        // Записываем код в сессию
        session()->put('promo_code', $promo->code);
        return back()->with('success', 'Промокод успешно применен!');
    }

    // Метод: Удалить промокод (если юзер передумал)
    public function removePromo(Tariff $tariff)
    {
        session()->forget('promo_code');
        return back();
    }
}