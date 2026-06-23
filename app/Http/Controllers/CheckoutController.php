<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\PromoCode;
use App\Models\Tariff; // Не забываем импортировать модель!
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function show(Request $request, Tariff $tariff, PranaService $prana)
    {
        if (! $tariff->is_active) {
            abort(404, 'Тариф недоступен для покупки.');
        }

        // Промокод из ссылки (?promo=CODE), например из блока «Выбор потока» на лендинге.
        // Валидный код кладём в сессию — дальше его подхватит computeState() и страница
        // отрисуется со скидкой на первом же рендере. Невалидный/чужой код просто игнорим.
        if ($request->filled('promo')) {
            $promo = PromoCode::where('code', $this->normalizeCode($request->query('promo')))->first();
            if ($promo
                && $promo->isValid()
                && $promo->appliesToCourse($tariff->course_id)
                && ! $this->alreadyUsedByCurrentUser($promo)
            ) {
                session()->put('promo_code', $promo->code);
            }
        }

        $tariff->load('course');
        $page = $tariff->course ? LandingPage::where('slug', $tariff->course->slug)->first() : new LandingPage(['title' => 'Оформление заказа']);

        $state = $this->computeState($tariff, $prana);

        return view('checkout.show', array_merge([
            'tariff' => $tariff,
            'page' => $page,
        ], $state));
    }

    // Применить промокод (поддерживает AJAX: возвращает JSON c пересчитанным состоянием)
    public function applyPromo(Request $request, Tariff $tariff, PranaService $prana)
    {
        $request->validate(['code' => 'required|string']);

        $code = $this->normalizeCode($request->code);
        $promo = PromoCode::where('code', $code)->first();

        if (! $promo || ! $promo->isValid()) {
            $message = 'Промокод не найден или истек срок его действия.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        // Код валиден, но привязан к другому курсу — на этом чекауте не применяем.
        if (! $promo->appliesToCourse($tariff->course_id)) {
            $message = 'Этот промокод действует только для другого курса.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        // Один человек — один раз. Для гостя проверить нельзя (анонимен) —
        // его поймает авторитетная проверка в PaymentController при оплате.
        if ($this->alreadyUsedByCurrentUser($promo)) {
            $message = 'Вы уже использовали этот промокод.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        session()->put('promo_code', $promo->code);

        if ($request->expectsJson() || $request->ajax()) {
            return $this->stateJson($tariff, $prana, 'Промокод успешно применён!');
        }

        return back()->with('success', 'Промокод успешно применен!');
    }

    // Снять промокод
    public function removePromo(Request $request, Tariff $tariff, PranaService $prana)
    {
        session()->forget('promo_code');

        if ($request->expectsJson() || $request->ajax()) {
            return $this->stateJson($tariff, $prana, 'Промокод снят.');
        }

        return back();
    }

    // Единый формат хранения кода промокода (верхний регистр, без пробелов по краям).
    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    // Правило «один человек — один раз»: применимо только к авторизованному юзеру.
    // Гость анонимен — его проверяет PaymentController по резолвнутому аккаунту.
    private function alreadyUsedByCurrentUser(PromoCode $promo): bool
    {
        return auth()->check() && $promo->redeemedByUser(auth()->id());
    }

    /**
     * Возвращает текущее состояние чекаута: цену с учётом всех скидок,
     * информацию о промокоде, лояльности и пране. Используется и в show(),
     * и в JSON-ответах AJAX-эндпоинтов.
     */
    private function computeState(Tariff $tariff, PranaService $prana): array
    {
        $user = auth()->user();

        $isLoyal = false;
        $loyaltyPercent = 0;

        if ($user) {
            $marketing = \App\Models\MarketingSetting::first();
            if ($marketing && $marketing->is_loyalty_active) {
                $hasAnyPaid = \App\Models\Payment::where('user_id', $user->id)->paid()->exists();
                if ($hasAnyPaid) {
                    $isLoyal = true;
                    $loyaltyPercent = $marketing->loyalty_discount_percent;
                }
            }
        }

        // Персональная скидка на курс имеет приоритет над лояльностью — для подписи в UI.
        $isPersonal = $user
            && \App\Models\StudentDiscount::activeFor($user->id, $tariff->course_id, $tariff->block_number) !== null;

        $basePrice = (float) $tariff->price;
        $finalPrice = $tariff->calculateFinalPriceForUser($user);
        $loyaltyDiscount = max(0.0, $basePrice - $finalPrice);

        $appliedPromo = null;
        $discountAmount = 0.0;

        if (session()->has('promo_code')) {
            $promo = PromoCode::where('code', session('promo_code'))->first();
            if ($promo && $promo->isValid()) {
                // Код валиден. Применяем, только если он подходит этому курсу
                // И юзер его ещё не гасил (один человек — один раз). При несовпадении
                // курса из сессии НЕ убираем — он может подойти на чекауте своего курса.
                if ($promo->appliesToCourse($tariff->course_id) && ! $this->alreadyUsedByCurrentUser($promo)) {
                    $appliedPromo = $promo;
                    $priceWithPromo = $promo->calculateDiscountedPrice($finalPrice);
                    $discountAmount = $finalPrice - $priceWithPromo;
                    $finalPrice = $priceWithPromo;
                }
            } else {
                session()->forget('promo_code');
            }
        }

        return [
            'finalPrice' => $finalPrice,
            'basePrice' => $basePrice,
            'loyaltyDiscount' => $loyaltyDiscount,
            'appliedPromo' => $appliedPromo,
            'discountAmount' => $discountAmount,
            'isLoyal' => $isLoyal,
            'loyaltyPercent' => $loyaltyPercent,
            'isPersonal' => $isPersonal,
            'pranaBalance' => $user ? $prana->balance($user) : 0,
            'pranaMaxSpend' => $user ? $prana->maxSpendableForPrice($user, $finalPrice) : 0,
            'pranaRate' => PranaSettings::rate(),
            'pranaSharePct' => (int) round(PranaSettings::maxShare() * 100),
        ];
    }

    private function stateJson(Tariff $tariff, PranaService $prana, string $message = ''): JsonResponse
    {
        $s = $this->computeState($tariff, $prana);

        return response()->json([
            'ok' => true,
            'message' => $message,
            'state' => [
                'finalPrice' => (int) round($s['finalPrice']),
                'basePrice' => (int) round($s['basePrice']),
                'loyaltyDiscount' => (int) round($s['loyaltyDiscount']),
                'discountAmount' => (int) round($s['discountAmount']),
                'pranaMaxSpend' => (int) $s['pranaMaxSpend'],
                'pranaRate' => (int) $s['pranaRate'],
                'pranaSharePct' => (int) $s['pranaSharePct'],
                'appliedPromo' => $s['appliedPromo'] ? [
                    'code' => $s['appliedPromo']->code,
                    'type' => $s['appliedPromo']->type,
                    'value' => (float) $s['appliedPromo']->value,
                ] : null,
                'isLoyal' => $s['isLoyal'],
                'loyaltyPercent' => (int) $s['loyaltyPercent'],
                'isPersonal' => $s['isPersonal'],
            ],
        ]);
    }
}
