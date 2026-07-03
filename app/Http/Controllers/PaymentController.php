<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Payments\TochkaPaymentService;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function createPayment(Request $request, PranaService $prana, TochkaPaymentService $tochka)
    {
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
        }

        $request->validate($rules);

        // Резолв пользователя — ВНЕ транзакции. Для гостя с существующим email —
        // отказ (анти-takeover), как в DepositController/TrialController. Иначе
        // аноним мог создавать платежи на чужой аккаунт и триггерить письмо со
        // сбросом пароля владельцу (Payment::sendWelcomeEmailIfNeeded).
        $user = $this->resolveUser($request);

        $tariff = Tariff::with('course')->findOrFail($request->input('tariff_id'));

        // Только запись в БД — в транзакции. HTTP-вызов в Tochka делается ПОСЛЕ
        // commit, иначе медленный/упавший эквайринг держит row-lock на
        // promo_codes/users/payments всё время сетевого запроса (см. DepositController).
        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $prana, $user, $tariff) {

            // 1. СЧИТАЕМ ИТОГОВУЮ ЦЕНУ
            $finalPrice = $tariff->calculateFinalPriceForUser($user);

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

                if ($promo
                    && $promo->isValid()
                    && $promo->appliesToCourse($tariff->course->id ?? null)
                    && ! $promo->redeemedByUser($user->id)
                    // Гонка в двух вкладках: redeemedByUser видит только paid, поэтому
                    // здесь дополнительно отсекаем свежий pending с тем же кодом.
                    && ! $promo->hasRecentPendingForUser($user->id)
                ) {
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
            }

            // 3. СОЗДАЕМ ПЛАТЕЖ
            $payment = Payment::create([
                'user_id' => $user->id,
                'course_id' => $tariff->course->id ?? null,
                'promo_code_id' => $promo?->id,
                'amount' => $finalPrice,
                'discount_percent' => $discount['percent'],
                'discount_amount' => $discount['amount'] > 0 ? $discount['amount'] : null,
                'prana_spent' => $pranaToSpend,
                'referral_credit_applied' => $referralCreditApplied > 0 ? $referralCreditApplied : null,
                'tariff' => $tariffKey,
                'status' => 'pending',
                'start_block' => $startBlock,
                'end_block' => $endBlock,
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
                    throw \Illuminate\Validation\ValidationException::withMessages([
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
        $purpose = 'Заказ №'.$payment->id.' | '.($tariff->course->title ?? 'Курс').' - '.$tariff->title;

        try {
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: (float) $finalPrice,
                purpose: $purpose,
                itemName: ($tariff->course->title ?? 'Курс').' — '.$tariff->title,
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
            $payment->update([
                'transaction_id' => $response['Data']['paymentLinkId'],
            ]);

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
            throw \Illuminate\Validation\ValidationException::withMessages([
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
        app(\App\Services\ReferralService::class)
            ->attachReferrer($user, $request->input('ref') ?: session('ref'));

        auth()->login($user);

        return $user;
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
        // Ничего не помечаем и не возвращаем прану здесь: это GET-возврат с банка,
        // которому нельзя доверять как факту провала. Источник истины — вебхук
        // Точки (WebhookController), который атомарно переведёт платёж в failed и
        // вернёт списанную прану. Иначе пользователь мог оплатить со скидкой прана,
        // затем открыть /payment/fail до вебхука и получить прану обратно, а вебхук
        // потом всё равно открыл бы доступ — двойная выгода. Плюс здесь брался
        // «последний pending» без привязки к заказу — мог свалиться не тот платёж.
        return redirect('/')->with('error', 'Оплата была отменена или произошла ошибка. Вы можете попробовать снова.');
    }
}
