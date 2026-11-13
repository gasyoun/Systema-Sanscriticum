<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\PaypalClaimReceivedMail;
use App\Models\BookClubOrder;
use App\Models\Payment;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\BookClub\BookClubPricing;
use App\Services\CuratorNotifier;
use App\Services\Payments\TochkaPaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * «Книжный клуб» — оплата доставки книг кампании planeta 219924 (H8195).
 *
 * Книги уже оплачены на кампании: чекаут продаёт ТОЛЬКО доставку. Паттерн —
 * зеркало MarathonController::pay (H471): bespoke Payment без строки Tariff,
 * course_id=null, tariff='book_club_delivery' (не ключ доступа — уроки не
 * открываются, см. Payment::grantAccess), Tochka ПОСЛЕ commit, purpose с
 * «Заказ №{id}» (иначе вебхук не найдёт платёж). Заграница — путь PayPal-заявки
 * (PaypalClaimController): pending + уведомление кураторов, сверка вручную.
 *
 * За фича-флагом features.book_club_delivery (BOOK_CLUB_DELIVERY, дефолт OFF):
 * флаг OFF — маршруты отдают 404, поведение прод-сборки не меняется.
 */
final class BookClubController extends Controller
{
    public function show(): View
    {
        abort_unless((bool) config('features.book_club_delivery'), 404);

        return view('book-club.checkout', [
            'countries' => (array) config('book_club.countries'),
            'abroadPrices' => (array) config('book_club.abroad_prices'),
            'rfPrice' => (float) config('book_club.rf_price'),
            'otherPrice' => (float) config('book_club.other_price'),
            'maxKg' => (int) config('book_club.max_kg'),
            'maxBooks' => (int) config('book_club.max_books_per_parcel'),
            'campaignUrl' => (string) config('book_club.campaign_url'),
            'paymentDeadline' => (string) config('book_club.payment_deadline'),
            'paypalMeLink' => (string) config('services.paypal.me_link'),
            'paypalRecipient' => (string) config('services.paypal.recipient'),
        ]);
    }

    public function submit(Request $request, TochkaPaymentService $tochka): RedirectResponse
    {
        abort_unless((bool) config('features.book_club_delivery'), 404);

        // Анти-флуд: один заказ за раз (зеркало marathon.pay).
        $rlKey = 'book-club-pay:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $isAbroad = BookClubPricing::isAbroad($request->input('country_code'));

        $rules = [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', Rule::in(BookClubPricing::countryCodes())],
            'country_name' => ['nullable', 'required_if:country_code,OTHER', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:32'],
            'address_line' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            // Матчинг с экспортом кампании (fulfillment_list.csv): email + № заказа.
            'planeta_email' => ['required', 'email', 'max:255'],
            'planeta_order_number' => ['required', 'string', 'max:64'],
        ];

        if ($isAbroad) {
            // PayPal-заявка: сумма/валюта + хотя бы одно доказательство оплаты
            // (зеркало StorePaypalClaimRequest — сверка платежа ручная).
            $rules['foreign_amount'] = ['required', 'numeric', 'min:1', 'max:1000000'];
            $rules['foreign_currency'] = ['required', 'in:USD,EUR'];
            $rules['paypal_txn'] = ['nullable', 'required_without:proof', 'string', 'max:255'];
            $rules['proof'] = ['nullable', 'required_without:paypal_txn', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
            $rules['comment'] = ['nullable', 'string', 'max:1000'];
        }

        $validated = $request->validate($rules);

        // Анти-дубль ДО резолва юзера: повторная отправка того же планета-заказа
        // не должна заводить второй платёж/второй аккаунт (money-core).
        $duplicate = BookClubOrder::where('planeta_email', $validated['planeta_email'])
            ->where('planeta_order_number', $validated['planeta_order_number'])
            ->whereHas('payment', fn ($q) => $q->whereNotIn('status', ['failed']))
            ->exists();

        if ($duplicate) {
            return back()
                ->withInput()
                ->with('error', 'Доставка для этого заказа planeta уже оформлена. Мы свяжемся с вами — повторно оплачивать не нужно.');
        }

        // Резолв юзера — вне транзакции (гость с существующим email — отказ,
        // анти-takeover; зеркало PaymentController::resolveUser).
        $user = $this->resolveUser($request);

        $amount = BookClubPricing::priceFor($validated['country_code']);

        // Чек оплаты из-за рубежа — приватный диск (в скрине могут быть платёжные данные).
        $proofPath = null;
        if ($isAbroad && $request->hasFile('proof')) {
            $proofPath = $request->file('proof')->store('paypal-proofs', 'local');
        }

        $noteParts = [
            'Книжный клуб 219924',
            'planeta: '.$validated['planeta_email'].' №'.$validated['planeta_order_number'],
        ];
        if ($isAbroad) {
            if (! empty($validated['paypal_txn'])) {
                $noteParts[] = 'PayPal txn: '.$validated['paypal_txn'];
            }
            if (! empty($validated['comment'])) {
                $noteParts[] = (string) $validated['comment'];
            }
        }

        // Только запись в БД — в транзакции; HTTP в Tochka/уведомления — после commit.
        $payment = DB::transaction(function () use ($user, $validated, $isAbroad, $amount, $proofPath, $noteParts): Payment {

            // Анти-дубль ещё раз, ПОД ЛОКОМ строк (паттерн H071 #2): параллельные
            // сабмиты одного планета-заказа сериализуются здесь — предпроверка до
            // транзакции отсекает обычный повтор, эта — гонку двух вкладок.
            $lockedDuplicate = BookClubOrder::where('planeta_email', $validated['planeta_email'])
                ->where('planeta_order_number', $validated['planeta_order_number'])
                ->lockForUpdate()
                ->get()
                ->contains(fn (BookClubOrder $order) => $order->payment && $order->payment->status !== 'failed');

            if ($lockedDuplicate) {
                throw ValidationException::withMessages([
                    'planeta_order_number' => 'Доставка для этого заказа planeta уже оформлена. Мы свяжемся с вами — повторно оплачивать не нужно.',
                ]);
            }

            $payment = Payment::create([
                'user_id' => $user->id,
                'course_id' => null,
                'amount' => $amount,
                'tariff' => 'book_club_delivery',
                'status' => 'pending',
                'provider' => $isAbroad ? Payment::PROVIDER_PAYPAL : null,
                'foreign_amount' => $isAbroad ? (float) $validated['foreign_amount'] : null,
                'foreign_currency' => $isAbroad ? $validated['foreign_currency'] : null,
                'proof_path' => $proofPath,
                'payer_note' => implode(' | ', $noteParts),
            ]);

            BookClubOrder::create([
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'planeta_email' => $validated['planeta_email'],
                'planeta_order_number' => $validated['planeta_order_number'],
                'recipient_name' => $validated['recipient_name'],
                'country_code' => $validated['country_code'],
                'country_name' => $validated['country_name'] ?? null,
                'city' => $validated['city'],
                'postal_code' => $validated['postal_code'],
                'address_line' => $validated['address_line'],
                'phone' => $validated['phone'] ?? null,
                'amount' => $amount,
            ]);

            return $payment;
        });

        // Заграница: PayPal-заявка (оплата уже сделана на paypal.me — сверка ручная).
        if ($isAbroad) {
            app(CuratorNotifier::class)->paypalClaimReceived($payment);

            $adminEmail = (string) config('services.admin.email');
            if ($adminEmail !== '') {
                Mail::to($adminEmail)->send(new PaypalClaimReceivedMail($payment));
            }

            return redirect()
                ->route('book-club.show')
                ->with('success', 'Спасибо! Заявка на доставку получена. Мы сверим платёж и пришлём подтверждение на email.');
        }

        // РФ: Tochka/СБП — после commit. Purpose с «Заказ №{id}» обязателен:
        // иначе вебхук Tochka не найдёт платёж.
        $purpose = 'Заказ №'.$payment->id.' | Книжный клуб — доставка';

        try {
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: $amount,
                purpose: $purpose,
                itemName: 'Книжный клуб — доставка (кампания '.config('book_club.campaign_id').')',
            );
        } catch (ConnectionException $e) {
            $payment->update(['status' => 'failed']);

            Log::error('Tochka недоступна (book_club_delivery)', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        }

        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $payment->update(['transaction_id' => $response['Data']['paymentLinkId']]);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $payment->update(['status' => 'failed']);

        Log::error('Ошибка Точка Эквайринг (book_club_delivery)', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
        ]);

        return back()->withInput()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * Резолв пользователя для чекаута (зеркало MarathonController::
     * resolveUserForCheckout / PaymentController::resolveUser).
     * Залогиненный — текущий. Гость с НОВЫМ email — создаём аккаунт и логиним.
     * Гость с СУЩЕСТВУЮЩИМ email — отказ (анти-takeover).
     */
    private function resolveUser(Request $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->input('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и оформите доставку оттуда.',
            ]);
        }

        $fullName = trim($request->input('surname').' '.$request->input('name'));
        $city = trim((string) $request->input('city'));
        if ($city !== '') {
            $fullName .= ', '.$city;
        }

        $user = User::create([
            'email' => $request->input('email'),
            'name' => $fullName,
            'password' => Hash::make(Str::random(12)),
        ]);

        app(AttributionService::class)->applyToNewUser($user);

        auth()->login($user);

        return $user;
    }
}
