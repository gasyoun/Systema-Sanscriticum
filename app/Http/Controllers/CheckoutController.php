<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\MembershipFunnelEvent;
use App\Models\PromoCode; // Не забываем импортировать модель!
use App\Models\StorefrontAnalyticsEvent;
use App\Models\StudentDiscount;
use App\Models\Tariff;
use App\Services\Activity\FunnelTelemetry;
use App\Services\Activity\StorefrontAnalytics;
use App\Services\CuratorNotifier;
use App\Services\Membership\MembershipFunnelAnalytics;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use App\Support\FlagshipExperiments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    public function show(Request $request, Tariff $tariff, PranaService $prana, FunnelTelemetry $funnel, MembershipFunnelAnalytics $membershipAnalytics)
    {
        if (! $tariff->is_active) {
            abort(404, 'Тариф недоступен для покупки.');
        }

        $sourceSite = (string) $request->query('source_site', '');
        if (in_array($sourceSite, ['samskrte', 'samskrtam', 'private_archive'], true)) {
            $request->session()->put("membership_checkout_source.{$tariff->id}", $sourceSite);
            $membershipAnalytics->record(
                MembershipFunnelEvent::CHECKOUT,
                $sourceSite,
                $request->user(),
                $tariff,
                archiveKey: $request->query('archive'),
                feature: $sourceSite === 'private_archive' ? 'private_archive_checkout' : 'autumn_storefront_checkout',
                request: $request,
                metadata: ['campaign' => (string) $request->query('campaign', '')],
            );
        }

        if (Auth::check()) {
            $funnel->emitBeginCheckout(
                Auth::user(),
                (int) $tariff->id,
                $tariff->course_id ? (int) $tariff->course_id : null,
                $request,
            );
        }

        $tariff->loadMissing('course');
        $course = $tariff->course;
        if ($course
            && FlagshipExperiments::ctaAbEnabled()
            && FlagshipExperiments::isFlagship($course)
        ) {
            app(StorefrontAnalytics::class)->record(
                event: StorefrontAnalyticsEvent::BEGIN_CHECKOUT,
                experiment: StorefrontAnalyticsEvent::EXPERIMENT_CTA_AB,
                request: $request,
                course: $course,
                variant: FlagshipExperiments::ctaVariantFromRequest($request),
            );
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

        // H3334 — режим «подарить»: нейтральная плашка и скрытое поле gift=1.
        // Флаг OFF → isGift всегда false, страница байт-в-байт прежняя.
        $isGift = (bool) config('features.gift_certificates') && $request->boolean('gift');

        $state = $this->computeState($tariff, $prana);

        return view('checkout.show', array_merge([
            'tariff' => $tariff,
            'page' => $page,
            'isGift' => $isGift,
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

    /**
     * Запрос «разбить оплату на части» с чекаута (H1290, ruling D6).
     *
     * Ничего не создаёт в деньгах: ни PaymentPromise, ни рассрочки, ни
     * пользователя — только уведомление кураторам через CuratorNotifier и
     * подтверждение студенту. График согласует куратор вручную в рамках
     * лимитов config/receivables.php (финансовое решение — fence rule 2).
     */
    public function requestInstallments(Request $request, Tariff $tariff, PranaService $prana, CuratorNotifier $notifier)
    {
        abort_unless($tariff->is_active, 404, 'Тариф недоступен для покупки.');

        $validated = $request->validateWithBag('installments', [
            // Гость анонимен — без контакта куратору некуда писать.
            'contact' => [$request->user() ? 'nullable' : 'required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'contact.required' => 'Оставьте контакт — Telegram, телефон или email, иначе куратору некуда написать.',
            'contact.max' => 'Слишком длинный контакт — достаточно одного способа связи.',
            'comment.max' => 'Слишком длинный комментарий — сократите до 1000 символов.',
        ]);

        // Та же цена, что студент видит на этой странице (лояльность + промокод).
        $state = $this->computeState($tariff, $prana);

        $notifier->installmentAskReceived(
            $tariff->load('course'),
            $request->user(),
            $validated['name'] ?? null,
            $validated['contact'] ?? null,
            $validated['comment'] ?? null,
            (float) $state['finalPrice'],
        );

        return redirect()
            ->to(route('checkout.show', $tariff).'#installments')
            ->with('installments_requested', true);
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
            // Реальный процент оптовой лояльности — тот же источник, что и в движке
            // цены (Tariff::getDiscountPercentForUser): считает уникальные ОПЛАЧЕННЫЕ
            // курсы за год против wholesale-порогов (и сам проверяет is_loyalty_active).
            // Раньше здесь читалась колонка loyalty_discount_percent, которую дропнула
            // миграция 2026_03_27_182336 → всегда null → бейдж «−0%»; а isLoyal
            // ставился при ЛЮБОМ paid-платеже (включая депозит/пробное/Расход), что
            // расходилось с реальной скидкой в цене.
            $loyaltyPercent = $tariff->getDiscountPercentForUser($user);
            $isLoyal = $loyaltyPercent > 0;
        }

        // Персональная скидка на курс имеет приоритет над лояльностью — для подписи в UI.
        $isPersonal = $user
            && StudentDiscount::activeFor($user->id, $tariff->course_id, $tariff->block_number) !== null;

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
