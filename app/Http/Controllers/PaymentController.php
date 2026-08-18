<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\CuratorNotifier;
use App\Services\NearDuplicateEmailDetector;
use App\Services\Payments\TochkaPaymentService;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use App\Services\ReferralService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function createPayment(Request $request, PranaService $prana, TochkaPaymentService $tochka)
    {
        // H1396 §2 — a logged-in student whose session lapsed between page load and
        // submit (the same anti-419 renewal window as §1) arrives here unauthenticated.
        // The form they were shown carried NO guest identity fields — those render only
        // inside @guest — so the default guest-required validation below would fire four
        // errors on fields they never saw, then hard-refuse their own email as "already
        // registered". A plain 419 was strictly more usable. Detect the state via the
        // checkout_authed marker the @auth form emits and send them to log back in with a
        // return to the same checkout, instead of rendering a guest form they never had.
        // Behind a default-OFF flag: with it off, behaviour is byte-identical to today.
        if (config('features.checkout_session_lapse_relogin')
            && ! auth()->check()
            && $request->boolean('checkout_authed')
        ) {
            $tariffId = $request->input('tariff_id');
            $intended = $tariffId
                ? route('checkout.show', $tariffId)
                : route('shop.index');
            session()->put('url.intended', $intended);

            return redirect()->route('login')
                ->with('error', 'Сессия истекла, пока вы оформляли заказ. Войдите снова — мы вернём вас к оплате.');
        }

        $rules = [
            'tariff_id' => 'required|exists:tariffs,id',
            'prana_amount' => 'nullable|integer|min:0',
        ];

        if (! auth()->check()) {
            $rules['name'] = 'required|string|max:255';
            $rules['surname'] = 'required|string|max:255';
            $rules['city'] = 'required|string|max:255';
            $rules['email'] = 'required|email|max:255';
            $rules['wants_announcements'] = 'nullable|boolean';
            $rules['birth_year'] = 'nullable|integer|min:1900|max:'.now()->format('Y');
            $rules['signup_source'] = ['nullable', 'string', Rule::in(AttributionService::SIGNUP_SOURCES)];
        }

        $request->validate($rules);

        // Авторитетная проверка активности выполняется ДО резолва гостя: прямой
        // POST с уже выключенным тарифом не должен создавать пользователя и не
        // должен доходить до банка. Флаг оставляет merge прод-инертным.
        $tariffQuery = Tariff::query()->with('course');

        if (config('features.checkout_inactive_tariff_guard')) {
            $tariffQuery->where('is_active', true);
        }

        $tariff = $tariffQuery->findOrFail($request->input('tariff_id'));

        // H3334 — режим «подарить»: тот же тариф, та же цена, но доступ
        // покупателю не открывается — после оплаты выпускается подарочный
        // сертификат с одноразовым кодом (см. GiftCertificateService).
        // Флаг OFF → параметр игнорируется, поведение байт-в-байт прежнее.
        $isGift = (bool) config('features.gift_certificates') && $request->boolean('gift');

        // A paid entitlement has no deliverable without a course group. Refuse the
        // checkout before creating a Payment or asking Tochka for a bank link; once
        // the course is configured, the customer can submit the checkout again.
        if ($tariff->course !== null && ! $tariff->course->groups()->exists()) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Оплата этого курса временно недоступна: группа доступа ещё не настроена.',
            ]);
        }

        // H1396 §1 — the checkout page survives its own session. The applied promo
        // used to live ONLY in session('promo_code'); the anti-419 CSRF refresh mints
        // a fresh empty session and remember-me re-auths the user, so the code was
        // gone here and the student was charged full price while the button showed the
        // discounted total. The code now travels in a hidden field and is re-resolved
        // AUTHORITATIVELY (it is client-supplied and forgeable — never trusted as sent).
        // Behind a default-OFF flag: with it off, createPayment behaves byte-identically
        // to today (session-only), so the money PR stays prod-inert until enabled.
        $sessionRenewalGuard = (bool) config('features.checkout_promo_survives_session');
        $priceConfirmed = $sessionRenewalGuard && $request->boolean('promo_lapse_confirmed');

        if ($sessionRenewalGuard) {
            $carriedPromoCode = $this->carriedPromoCode($request);

            if ($carriedPromoCode !== null && ! $priceConfirmed) {
                $promo = PromoCode::where('code', $carriedPromoCode)->first();

                if ($promo !== null) {
                    // Guest (null) has redeemed nothing yet — the calendar/capacity/tariff
                    // checks below are the ones that matter for the confirmation decision.
                    $lapseReason = $this->promoLapseReason($promo, $tariff, auth()->user());

                    if ($lapseReason !== null) {
                        // RULED 20-07-2026 (MG): a lapsed promo must not silently proceed to
                        // the bank at full price, nor be hard-refused — show the new total and
                        // require an explicit confirmation before charging it.
                        return $this->promoLapsedConfirmation($request, $tariff, $lapseReason);
                    }

                    // Still applicable — re-hydrate the session so the authoritative
                    // transaction below applies exactly this code, one code path.
                    session()->put('promo_code', $promo->code);
                }
            }

            if ($priceConfirmed) {
                // The student explicitly accepted the higher (promo-less) total.
                session()->forget('promo_code');
            }
        }

        // Резолв пользователя — ВНЕ транзакции. Для гостя с существующим email —
        // отказ (анти-takeover), как в DepositController/TrialController. Иначе
        // аноним мог создавать платежи на чужой аккаунт и триггерить письмо со
        // сбросом пароля владельцу (Payment::sendWelcomeEmailIfNeeded).
        $user = $this->resolveUser($request);

        // Re-check once more at the confirmed submit: the window between showing the
        // confirmation and this request is itself a lapse opportunity. If the
        // promo-less price has risen above what the confirmation screen pinned, confirm
        // again rather than charging more than was shown (the ruling's invariant).
        if ($priceConfirmed && $request->filled('confirmed_total')) {
            $authoritativeFull = $tariff->calculateFinalPriceForUser($user);
            if ($authoritativeFull > (float) $request->input('confirmed_total') + 1) {
                return $this->promoLapsedConfirmation($request, $tariff, 'цена изменилась');
            }
        }

        // Только запись в БД — в транзакции. HTTP-вызов в Tochka делается ПОСЛЕ
        // commit, иначе медленный/упавший эквайринг держит row-lock на
        // promo_codes/users/payments всё время сетевого запроса (см. DepositController).
        $result = DB::transaction(function () use ($request, $prana, $user, $tariff, $isGift) {
            // Auth может держать модель User, загруженную до начала транзакции.
            // При включённом флаге перечитываем и лочим кошелёк первым действием:
            // все расчёты и списание ниже используют один DB-authoritative снимок.
            if (config('features.checkout_referral_credit_lock')) {
                $user = User::query()
                    ->whereKey($user->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $courseId = $tariff->course->id ?? null;

            if ($courseId) {
                // Лочим ещё не потраченные депозит/пробное этого курса — сериализует
                // параллельные createPayment на один и тот же курс/пользователя.
                Payment::query()
                    ->where('user_id', $user->id)
                    ->where('course_id', $courseId)
                    ->unconsumedDeposits()
                    ->lockForUpdate()
                    ->get();

                // Депозит/пробное списывается (deposit_consumed_at) только когда
                // РЕАЛЬНЫЙ платёж дойдёт до paid — не в момент создания pending.
                // Значит, пока он не потрачен, каждый новый pending-заказ на этот
                // курс получает СВОЙ полный вычет того же депозита, и оба потом
                // могут быть оплачены (money-core, H071 #2: 2000₽ депозит был
                // зачтён дважды на два разных block-заказа). Раз в момент оплаты
                // неизвестно, какой из параллельных заказов «первый», блокируем
                // создание ВТОРОГО pending-заказа на курс, пока есть неизрасходованный
                // депозит и уже открыт другой (не deposit/trial) pending той же покупки.
                $hasUnspentDeposit = Payment::query()
                    ->where('user_id', $user->id)
                    ->where('course_id', $courseId)
                    ->unconsumedDeposits()
                    ->exists();

                if ($hasUnspentDeposit && Payment::query()->hasOtherPendingOrderForCourse($user->id, $courseId)->exists()) {
                    throw ValidationException::withMessages([
                        'tariff_id' => 'У вас уже есть незавершённый заказ по этому курсу, использующий бронь/предоплату. Завершите оплату или отмените его, прежде чем оформлять новый.',
                    ]);
                }
            }

            // 1. СЧИТАЕМ ИТОГОВУЮ ЦЕНУ
            $finalPrice = $tariff->calculateFinalPriceForUser($user);

            // Сколько предоплаты (депозит/пробное) реально зачтено в эту цену —
            // пишется в платёж, чтобы при оплате погасить депозиты ровно на эту
            // сумму (остаток переживает покупку, H071 #10) и чтобы зачёт при
            // докупке видел полную стоимость покупки (net-кэш + депозитная
            // часть, H071 #9). Всегда не-null для чекаута: 0.0 = «предоплата не
            // применялась» (null зарезервирован за легаси-строками до колонки).
            $depositCreditApplied = $tariff->prepaidCreditAppliedForUser($user);

            // Фиксируем скидку (персональная/лояльность) для пометки в админке и
            // выгрузке в Google Sheet. fixed-скидка пишет только рубли, percent —
            // и процент, и рублёвый эквивалент. Промокод/прана — отдельно.
            $discount = $tariff->discountInfoForUser($user);

            // 2. Применяем промокод
            $promo = null;
            if (session()->has('promo_code')) {
                $promo = PromoCode::where('code', session('promo_code'))
                    ->lockForUpdate()
                    ->first();

                $eligibleBeforeCapacity = $promo
                    && $promo->isCurrentlyActive()
                    && $promo->appliesToCourse($tariff->course->id ?? null)
                    && ! $promo->redeemedByUser($user->id);

                if (config('features.checkout_promo_reservations') && $eligibleBeforeCapacity) {
                    if ($promo->hasRecentPendingForUser($user->id)) {
                        throw ValidationException::withMessages([
                            'promo_code' => 'У вас уже есть незавершённый заказ с этим промокодом. Завершите оплату или дождитесь истечения ссылки.',
                        ]);
                    }

                    if (! $promo->hasCapacity()) {
                        throw ValidationException::withMessages([
                            'promo_code' => 'Лимит использований промокода уже занят. Оплата по показанной скидке недоступна.',
                        ]);
                    }
                }

                $applicable = $promo
                    && $promo->isValid()
                    && $promo->appliesToCourse($tariff->course->id ?? null)
                    && ! $promo->redeemedByUser($user->id);

                // Свежий pending с тем же кодом (гонка двух вкладок / возврат на
                // чекаут): НЕ создаём второй заказ по цене выше показанной. Раньше
                // промокод здесь молча обнулялся, и юзер уходил в банк на ПОЛНУЮ
                // сумму, а чекаут за клик до этого показывал цену со скидкой
                // (money-core, H071 #13). Теперь — явная ошибка вместо тихого
                // повышения цены.
                if (! config('features.checkout_promo_reservations')
                    && $applicable
                    && $promo->hasRecentPendingForUser($user->id)
                ) {
                    throw ValidationException::withMessages([
                        'promo_code' => 'У вас уже есть незавершённый заказ с этим промокодом. Завершите оплату или отмените его, прежде чем оформлять новый.',
                    ]);
                }

                if ($applicable) {
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

            // --- РЕФЕРАЛЬНЫЙ КРЕДИТ: авто-зачёт остатка из кошелька покупателя ---
            // Это собственные заработанные деньги студента — применяем автоматически,
            // сколько нужно (но не больше остатка цены). Списываем в той же транзакции;
            // при срыве оплаты Payment::refundReferralCreditIfApplied() вернёт обратно.
            $referralCreditApplied = 0.0;
            $availableCredit = (float) ($user->referral_credit ?? 0);
            if ($availableCredit > 0 && $finalPrice > 0) {
                $referralCreditApplied = min($availableCredit, $finalPrice);
                $finalPrice = max(0, $finalPrice - $referralCreditApplied);
            }

            // --- ОПРЕДЕЛЯЕМ КЛЮЧ ДЛЯ ДОСТУПА И НОМЕРА БЛОКОВ ---
            // accessKey() — единственный источник ключа доступа: не-блочные тарифы
            // ('full'/'vip'/'bundle') → 'full', целый блок → 'block_N', половина →
            // 'block_N_hH'. Раньше сюда писался сырой $tariff->type, поэтому оплаченный
            // 'vip'/'bundle' сохранялся в payments.tariff как 'vip'/'bundle' и не
            // совпадал ни с одним Lesson::unlockingKeys() → студент не открывал уроки.
            $tariffKey = $tariff->accessKey();
            $startBlock = null;
            $endBlock = null;

            if ($tariff->type === 'block') {
                // 💡 Сохраняем номер блока — для отчётности в Google Sheets
                // и чтобы администратор видел, за какой блок платят (для половины — тот же блок)
                $startBlock = $tariff->block_number;
                $endBlock = $tariff->block_number;
            } elseif ($tariff->type === 'bundle' && $tariff->start_block !== null && $tariff->end_block !== null) {
                // Bundle-тариф на диапазон блоков: ключ = block_{start} (accessKey),
                // а недостающие ключи диапазона дорисует BlockAccessMaterializer по
                // start_block..end_block после оплаты.
                $startBlock = $tariff->start_block;
                $endBlock = $tariff->end_block;
            }

            // 3. СОЗДАЕМ ПЛАТЕЖ
            // H2186: attach newest unconverted Lead by email only when Rate B
            // instrumentation flag is ON (default OFF — checkout otherwise unchanged).
            $leadId = null;
            if (config('features.lead_converted_at_on_course_paid') && filled($user->email)) {
                $leadId = Lead::query()
                    ->where('email', $user->email)
                    ->whereNull('converted_at')
                    ->latest()
                    ->value('id');
            }

            $payment = Payment::create([
                'user_id' => $user->id,
                'lead_id' => $leadId,
                'course_id' => $tariff->course->id ?? null,
                'promo_code_id' => $promo?->id,
                'amount' => $finalPrice,
                'discount_percent' => $discount['percent'],
                'discount_amount' => $discount['amount'] > 0 ? $discount['amount'] : null,
                'prana_spent' => $pranaToSpend,
                'referral_credit_applied' => $referralCreditApplied > 0 ? $referralCreditApplied : null,
                'deposit_credit_applied' => $depositCreditApplied,
                // H3334: в payments.tariff пишется маркер 'gift', а снимок
                // «что подарено» (ключ доступа из Tariff::accessKey(), название,
                // диапазон блоков bundle) — в claim_meta; GiftCertificateService
                // выпустит по нему сертификат при оплате. Containment-модель
                // не тронута: получатель при активации получит платёж с тем же
                // accessKey, каким открылся бы обычный чекаут этого тарифа.
                'tariff' => $isGift ? 'gift' : $tariffKey,
                'status' => 'pending',
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                'claim_meta' => $isGift ? [
                    'gift_tariff_key' => $tariffKey,
                    'gift_tariff_title' => (string) $tariff->title,
                    'gift_start_block' => $startBlock,
                    'gift_end_block' => $endBlock,
                ] : null,
            ]);

            // Списываем реферальный кредит ровно сейчас, в той же транзакции (как прану).
            if ($referralCreditApplied > 0) {
                $user->decrement('referral_credit', $referralCreditApplied);
            }

            // Списываем прану ровно сейчас, в той же транзакции — потом, если оплата
            // не пройдёт, наблюдатель Payment::updated вернёт её через refundPranaIfSpent().
            if ($pranaToSpend > 0) {
                try {
                    $prana->spend($user, $pranaToSpend, 'spent_on_purchase', $payment);
                } catch (\RuntimeException $e) {
                    // Race: вторая вкладка/двойной клик успели списать раньше. Не отдаём 500 —
                    // транзакция откатится по ValidationException, юзер вернётся к форме с ошибкой.
                    throw ValidationException::withMessages([
                        'prana_amount' => 'Не удалось списать прану — обновите страницу и попробуйте снова.',
                    ]);
                }
            }

            // 4. ИНКРЕМЕНТИРУЕМ ПРОМОКОД
            // used_count растёт только по подтверждённой оплате (Payment::redeemPromoOnPaid),
            // а не здесь — иначе брошенные/незавершённые чекауты исчерпывали бы лимит.
            if ($promo) {
                session()->forget('promo_code');
            }

            return [$payment, $finalPrice, $tariff];
        });

        [$payment, $finalPrice, $tariff] = $result;

        // 5. ЕСЛИ ЦЕНА 0 — доступ открывается сразу (после commit; наблюдатель
        // Payment::updated по статусу paid выдаёт доступ к группам).
        if ($finalPrice == 0) {
            $payment->update(['status' => 'paid']);

            if (! auth()->check()) {
                return redirect()->route('login')
                    ->with('success', 'Доступ открыт! Войдите в аккаунт, чтобы начать обучение.');
            }

            return redirect()->route('student.dashboard')
                ->with('success', 'Доступ к курсу успешно открыт!');
        }

        // 6. ОТПРАВЛЯЕМ ЗАПРОС В ТОЧКУ — ПОСЛЕ commit (с фискализацией: чек уйдёт на email студента)
        // Инвариант вебхука Точки: purpose обязан начинаться с «Заказ №{id}» —
        // WebhookController матчит строго /Заказ №(\d+)/. Подарочный префикс рвал
        // матчинг: paid-переход не наступал и сертификат не выпускался.
        $purpose = 'Заказ №'.$payment->id
            .' | '.($isGift ? 'Подарочный сертификат — ' : '')
            .($tariff->course->title ?? 'Курс').' - '.$tariff->title;

        $promoTtlMinutes = config('features.checkout_promo_reservations') && $payment->promo_code_id
            ? PromoCode::PAYMENT_LINK_TTL_MINUTES
            : null;

        // H1396 §3 — hand the bank a SIGNED return URL carrying this payment's id, so
        // identification survives a lost session cookie (in-app WebView → external
        // browser is a different cookie jar) and two pending orders never resolve to the
        // wrong one. Behind a default-OFF flag; when off, the service falls back to the
        // legacy unsigned paramless routes and behaviour is unchanged.
        $signedRedirectUrl = null;
        $signedFailRedirectUrl = null;
        if (config('features.checkout_signed_return_url')) {
            $signedRedirectUrl = URL::signedRoute('payment.success', ['payment' => $payment->id]);
            $signedFailRedirectUrl = URL::signedRoute('payment.fail', ['payment' => $payment->id]);
        }

        try {
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: (float) $finalPrice,
                purpose: $purpose,
                itemName: ($tariff->course->title ?? 'Курс').' — '.$tariff->title,
                ttlMinutes: $promoTtlMinutes,
                redirectUrl: $signedRedirectUrl,
                failRedirectUrl: $signedFailRedirectUrl,
            );
        } catch (ConnectionException $e) {
            // Сетевой сбой / TLS / DNS / timeout. Помечаем платёж failed (наблюдатель
            // вернёт списанную прану) и возвращаем мягкую ошибку для повторной попытки.
            $payment->update(['status' => 'failed']);

            Log::error('Tochka недоступна', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        }

        // 7. ОБРАБАТЫВАЕМ ОТВЕТ
        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $paymentUpdate = [
                'transaction_id' => $response['Data']['paymentLinkId'],
            ];

            if ($promoTtlMinutes !== null) {
                $paymentUpdate['payment_link_expires_at'] = now()->addMinutes($promoTtlMinutes);
            }

            $payment->update($paymentUpdate);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $payment->update(['status' => 'failed']);

        Log::error('Ошибка Точка Эквайринг', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * H1396 §1 — the promo code carried in the hidden checkout field. Falls back to
     * the session when the field is absent (older cached forms), so behaviour is
     * unchanged when nothing is carried. Normalised exactly like
     * CheckoutController::normalizeCode so a forged/hand-typed value still matches.
     */
    private function carriedPromoCode(Request $request): ?string
    {
        $raw = $request->input('promo_code');
        $code = is_string($raw) ? mb_strtoupper(trim($raw)) : '';

        if ($code === '') {
            $code = (string) session('promo_code', '');
        }

        return $code === '' ? null : $code;
    }

    /**
     * Why a carried promo no longer applies, or null if it still does. The reason is
     * distinguished where cheap — "промокод больше не действует" with no reason is what
     * generates support load. Guest ($user === null) has redeemed nothing yet.
     */
    private function promoLapseReason(PromoCode $promo, Tariff $tariff, ?User $user): ?string
    {
        if (! $promo->isCurrentlyActive()) {
            return 'срок действия промокода истёк';
        }

        if (! $promo->appliesToCourse($tariff->course->id ?? $tariff->course_id)) {
            return 'промокод не действует для этого курса';
        }

        if ($user !== null && $promo->redeemedByUser($user->id)) {
            return 'вы уже использовали этот промокод';
        }

        if (! $promo->hasCapacity()) {
            return 'исчерпан лимит использований промокода';
        }

        return null;
    }

    /**
     * The explicit-confirmation surface for a lapsed promo (MG ruling 20-07-2026).
     * A distinct, deliberate step — NOT a re-render of the same pay button with a
     * quietly different number. It states the new (promo-less) total, carries the
     * original order inputs forward as hidden fields, and pins the shown total in
     * `confirmed_total` so the eventual charge can be asserted equal to it.
     */
    private function promoLapsedConfirmation(Request $request, Tariff $tariff, string $reason)
    {
        // The promo-less total the student will now pay, computed authoritatively.
        $newTotal = $tariff->calculateFinalPriceForUser(auth()->user());

        // The lapsed code no longer helps — drop it so a later navigation does not
        // re-show a discount that is gone.
        session()->forget('promo_code');

        $carry = $request->only([
            'tariff_id', 'prana_amount', 'name', 'surname', 'city', 'email',
            'wants_announcements', 'birth_year', 'signup_source', 'ref',
        ]);

        return response()->view('checkout.confirm-price', [
            'tariff' => $tariff,
            'reason' => $reason,
            'newTotal' => $newTotal,
            'carry' => $carry,
        ]);
    }

    /**
     * Возвращает пользователя для оформления платежа.
     *
     * Залогиненный — текущий. Гость с НОВЫМ email — создаём аккаунт и логиним
     * (риска takeover нет: владелец сам только что ввёл email). Гость с
     * СУЩЕСТВУЮЩИМ email — отказ: раньше платёж молча создавался на чужой аккаунт,
     * а первая оплата перегенерировала владельцу пароль и слала письмо.
     */
    private function resolveUser(Request $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->input('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и оформите заказ оттуда.',
            ]);
        }

        // Склейка ФИО+город прямо в name: «Фамилия Имя, Город»
        $fullName = trim($request->input('surname').' '.$request->input('name'));
        $city = trim((string) $request->input('city'));
        if ($city !== '') {
            $fullName .= ', '.$city;
        }

        $user = User::create([
            'email' => $request->input('email'),
            'name' => $fullName,
            'password' => Hash::make(Str::random(12)),
            'wants_email_announcements' => $request->boolean('wants_announcements'),
        ]);

        // Реферал: привязываем нового студента к пригласившему по коду
        // (из формы или сохранённого в сессии при переходе по ссылке).
        app(ReferralService::class)
            ->attachReferrer($user, $request->input('ref') ?: session('ref'));

        // A1: источник (UTM/реферер с первого визита) + лид-мэтчинг; год
        // рождения и «откуда о нас узнали» (H476) — необязательные поля чекаута.
        $attribution = app(AttributionService::class);
        $attribution->applyToNewUser($user);
        if ($request->filled('birth_year')) {
            $attribution->applyBirthYear($user, $request->input('birth_year'));
        }
        if ($request->filled('signup_source')) {
            $attribution->applySignupSource($user, $request->input('signup_source'));
        }

        // Default OFF (config/features.php checkout_near_duplicate_email_guard).
        // Advisory only — never blocks this checkout, just pings curators to
        // merge manually if it really is the same student under a typo'd
        // email (H incident: Долгополова Анастасия, 2026-08-18).
        if (config('features.checkout_near_duplicate_email_guard')) {
            $nearDuplicates = app(NearDuplicateEmailDetector::class)->findFor($user);
            foreach ($nearDuplicates as $existing) {
                app(CuratorNotifier::class)->possibleDuplicateAccount($user, $existing);
            }
        }

        auth()->login($user);

        return $user;
    }

    public function success(Request $request)
    {
        // H1285: рендерим страницу вместо редиректа. Только чтение — статус
        // платежа меняет исключительно вебхук Точки (см. комментарий в fail()).
        $payment = $this->resolveReturnPayment($request);

        $confirmed = $payment && in_array($payment->status, Payment::PAID_STATUSES, true);

        return view('payment.success', [
            'payment' => $payment,
            'confirmed' => $confirmed,
        ]);
    }

    public function fail(Request $request)
    {
        // Ничего не помечаем и не возвращаем прану здесь: это GET-возврат с банка,
        // которому нельзя доверять как факту провала. Источник истины — вебхук
        // Точки (WebhookController), который атомарно переведёт платёж в failed и
        // вернёт списанную прану. Иначе пользователь мог оплатить со скидкой прана,
        // затем открыть /payment/fail до вебхука и получить прану обратно, а вебхук
        // потом всё равно открыл бы доступ — двойная выгода. Плюс здесь брался
        // «последний pending» без привязки к заказу — мог свалиться не тот платёж.
        //
        // H1285: вместо выброса на главную рендерим страницу с повтором оплаты.
        // Курс для ссылки повтора восстанавливаем ТОЛЬКО для отображения из
        // последнего неоплаченного платежа — неточность здесь стоит лишнего
        // клика, а не денег. Скрытый курс отдал бы 404 — тогда ведём в каталог.
        //
        // H1396 §3 — если банк вернул подписанный id заказа, берём курс именно того
        // платежа (переживает потерю сессии/куки); иначе — прежний путь по последнему
        // неоплаченному платежу авторизованного юзера.
        $course = null;

        $signed = $this->signedReturnPayment($request);
        if ($signed !== null) {
            $course = $signed->course;
        } elseif (auth()->check()) {
            $course = Payment::query()
                ->where('user_id', auth()->id())
                ->whereNotIn('status', Payment::PAID_STATUSES)
                ->with('course')
                ->latest('id')
                ->first()
                ?->course;
        }

        if ($course && ! $course->is_visible) {
            $course = null;
        }

        return view('payment.fail', [
            'course' => $course,
        ]);
    }

    /**
     * H1396 §3 — the order the student returned from the bank on.
     *
     * Prefers the signed payment id the bank echoed back (survives a lost session
     * cookie: an in-app WebView hands off to an external browser with a different
     * cookie jar), and only then falls back to the legacy "latest payment of the
     * authenticated user", which showed the wrong order when two were pending and a
     * guest "log in" screen to a student who genuinely paid. Returns null when
     * neither identifies an order.
     */
    private function resolveReturnPayment(Request $request): ?Payment
    {
        $signed = $this->signedReturnPayment($request);
        if ($signed !== null) {
            return $signed;
        }

        if (auth()->check()) {
            return Payment::query()
                ->where('user_id', auth()->id())
                ->with('course')
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * The payment named by a *valid* signed return URL, or null. The signature is an
     * HMAC over the full URL, so a present, valid signature proves the id was minted by
     * us (in createPayment) and not tampered — the link is unguessable and reached the
     * student only via their own bank redirect. Gated by a default-OFF flag; with it
     * off, signed ids are ignored and the session-based path is used.
     */
    private function signedReturnPayment(Request $request): ?Payment
    {
        if (! config('features.checkout_signed_return_url')
            || ! $request->hasValidSignature()
            || ! $request->filled('payment')
        ) {
            return null;
        }

        return Payment::query()->with('course')->find($request->query('payment'));
    }
}
