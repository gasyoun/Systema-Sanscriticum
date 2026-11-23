<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\BookClubAdminOrderMail;
use App\Mail\PaypalClaimReceivedMail;
use App\Models\Book;
use App\Models\BookClubOrder;
use App\Models\Payment;
use App\Services\BookClub\BookClubCheckout;
use App\Services\BookClub\BookClubPricing;
use App\Services\BookClub\BookClubShopPricing;
use App\Services\BookClub\BookStock;
use App\Services\CuratorNotifier;
use App\Services\Payments\TochkaPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «Книжный клуб» → МАГАЗИН (H8412): витрина /knizhny-klub/kupit.
 *
 * Продаёт книгу «Школьные лекции» 1000 ₽ × N плюс доставку или самовывоз —
 * без полей кампании planeta (решения грилла 17-11-2026). Остаток — books.stock
 * (единственный источник, Filament-админка); при 0 витрина честно говорит
 * «под заказ 2–3 недели» и заказ всё равно принимает.
 *
 * Деньги: РФ — Tochka/СБП автоматом (одна агрегированная позиция чека, Q10);
 * зарубеж — PayPal-заявка с ручной сверкой (как на /dostavka); самовывоз и
 * заказы 5+ книг — заказ создаётся, доставка/сумма согласуется вручную,
 * письмо администратору (деньги не теряются).
 *
 * Флаг features.book_club_shop (BOOK_CLUB_SHOP, дефолт OFF): OFF — 404.
 */
final class BookClubShopController extends Controller
{
    public function show(): View
    {
        abort_unless((bool) config('features.book_club_shop'), 404);

        $book = $this->book();
        abort_if($book === null, 404);

        return view('book-club.shop', [
            'book' => $book,
            'countries' => (array) config('book_club.countries'),
            'abroadPrices' => (array) config('book_club.abroad_prices'),
            'rfPrice' => (float) config('book_club.rf_price'),
            'otherPrice' => (float) config('book_club.other_price'),
            'maxBooks' => (int) config('book_club.max_books_per_parcel'),
            'manualThreshold' => (int) config('book_club.manual_quote_threshold'),
            'pickupEnabled' => (bool) config('book_club.pickup_enabled'),
            'pickupNote' => (string) config('book_club.pickup_note'),
            'paypalMeLink' => (string) config('services.paypal.me_link'),
            'paypalRecipient' => (string) config('services.paypal.recipient'),
        ]);
    }

    public function submit(
        Request $request,
        TochkaPaymentService $tochka,
        BookClubCheckout $checkout,
        BookStock $stock,
    ): RedirectResponse {
        abort_unless((bool) config('features.book_club_shop'), 404);

        $book = $this->book();
        abort_if($book === null, 404);

        // Анти-флуд: один заказ за раз (зеркало /dostavka).
        $rlKey = 'book-club-shop:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $pickup = $request->input('fulfillment') === BookClubOrder::MODE_PICKUP;

        $rules = [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'fulfillment' => ['required', Rule::in([BookClubOrder::MODE_PURCHASE, BookClubOrder::MODE_PICKUP])],
            'books_count' => ['required', 'integer', 'min:1', 'max:99'],
        ];

        // Самовывоз адреса не требует вовсе (Q15); доставка — требует.
        if (! $pickup) {
            $rules += [
                'recipient_name' => ['required', 'string', 'max:255'],
                'country_code' => ['required', Rule::in(BookClubPricing::countryCodes())],
                'country_name' => ['nullable', 'required_if:country_code,OTHER', 'string', 'max:255'],
                'city' => ['required', 'string', 'max:255'],
                'postal_code' => ['required', 'string', 'max:32'],
                'address_line' => ['required', 'string', 'max:255'],
            ];
        }

        $isAbroad = ! $pickup && BookClubPricing::isAbroad($request->input('country_code'));

        // 5+ книг: сумму согласуем письмом, поэтому PayPal-предоплату и её
        // доказательства НЕ требуем — иначе покупатель платит до согласования.
        $manualQuote = BookClubShopPricing::requiresManualQuote((int) $request->input('books_count', 0));

        if ($isAbroad && ! $manualQuote) {
            // PayPal-заявка: сумма/валюта + хотя бы одно доказательство оплаты.
            $rules += [
                'foreign_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
                'foreign_currency' => ['required', 'in:USD,EUR'],
                'paypal_txn' => ['nullable', 'required_without:proof', 'string', 'max:255'],
                'proof' => ['nullable', 'required_without:paypal_txn', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ];
        }

        $validated = $request->validate($rules);

        $count = (int) $validated['books_count'];
        $countryCode = $pickup ? null : (string) $validated['country_code'];
        $quote = BookClubShopPricing::quote($book, $count, $countryCode, $pickup);

        // Резолв юзера — вне транзакции (анти-takeover общего сервиса чекаута).
        $user = $checkout->resolveUser($request);

        $mode = $pickup ? BookClubOrder::MODE_PICKUP : BookClubOrder::MODE_PURCHASE;

        // Анти-дубль: тот же покупатель, та же книга/количество/режим в пределах
        // 10 минут — второй платёж не создаём (двойной клик, две вкладки).
        $duplicateCriteria = [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'mode' => $mode,
            'books_count' => $count,
        ];

        if ($checkout->activeDuplicateExists($duplicateCriteria, 10)) {
            return back()
                ->withInput()
                ->with('error', 'Такой заказ уже оформлен. Мы свяжемся с вами — повторно оплачивать не нужно.');
        }

        // Чек оплаты из-за рубежа — приватный диск (в скрине платёжные данные).
        // Только для не-ручных заказов: 5+ книг платятся после согласования суммы.
        $proofPath = null;
        if ($isAbroad && ! $manualQuote && $request->hasFile('proof')) {
            $proofPath = $request->file('proof')->store('paypal-proofs', 'local');
        }

        $noteParts = [
            'Книжный клуб — книга «'.config('book_club.book_title').'» ×'.$count,
            $pickup ? 'самовывоз' : ('доставка '.$countryCode.' · посылок '.$quote['parcels']),
        ];
        if ($quote['manual']) {
            $noteParts[] = 'сумма к согласованию ('.$count.' книг)';
        }
        if ($isAbroad) {
            if (! empty($validated['paypal_txn'])) {
                $noteParts[] = 'PayPal txn: '.$validated['paypal_txn'];
            }
            if (! empty($validated['comment'])) {
                $noteParts[] = (string) $validated['comment'];
            }
        }

        // Резерв остатка + денежная пара в одной транзакции. Заказ создаётся в
        // любом случае (5+, «под заказ») — деньги не теряются, Q2/Q13.
        $order = DB::transaction(function () use ($checkout, $stock, $book, $count, $user, $mode, $validated, $pickup, $isAbroad, $quote, $proofPath, $noteParts, $duplicateCriteria): BookClubOrder {
            $stock->reserve($book, $count);

            return $checkout->createOrder(
                paymentAttributes: [
                    'user_id' => $user->id,
                    'course_id' => null,
                    'amount' => $quote['total'],
                    'tariff' => 'book_club_shop',
                    'status' => 'pending',
                    'provider' => ($isAbroad && ! $quote['manual']) ? Payment::PROVIDER_PAYPAL : null,
                    'foreign_amount' => ($isAbroad && ! $quote['manual']) ? (float) $validated['foreign_amount'] : null,
                    'foreign_currency' => ($isAbroad && ! $quote['manual']) ? $validated['foreign_currency'] : null,
                    'proof_path' => $proofPath,
                    'payer_note' => implode(' | ', $noteParts),
                ],
                orderAttributes: [
                    'user_id' => $user->id,
                    'book_id' => $book->id,
                    'mode' => $mode,
                    'books_count' => $count,
                    'recipient_name' => $pickup
                        ? (string) $user->name
                        : (string) $validated['recipient_name'],
                    'country_code' => $pickup ? null : $validated['country_code'],
                    'country_name' => $pickup ? null : ($validated['country_name'] ?? null),
                    'city' => $pickup ? null : $validated['city'],
                    'postal_code' => $pickup ? null : $validated['postal_code'],
                    'address_line' => $pickup ? null : $validated['address_line'],
                    'phone' => $validated['phone'] ?? null,
                    'amount' => $quote['total'],
                    'delivery_amount' => $quote['delivery'],
                    'status' => BookClubOrder::STATUS_PENDING,
                ],
                duplicateCriteria: $duplicateCriteria,
                duplicateWithinMinutes: 10,
                duplicateField: 'books_count',
                duplicateMessage: 'Такой заказ уже оформлен. Мы свяжемся с вами — повторно оплачивать не нужно.',
            );
        });

        $payment = $order->payment;

        // Самовывоз и 5+ книг: сумма/время согласуются вручную — онлайн-платёж
        // не запускаем, администратор получает письмо, заказ остаётся pending.
        if ($pickup || $quote['manual']) {
            $this->notifyAdmin($order);

            return redirect()
                ->route('book-club.shop')
                ->with('success', $pickup
                    ? 'Заказ принят. Самовывоз: '.config('book_club.pickup_note')
                    : 'Заказ принят. Книг больше четырёх — сумму и доставку согласуем с вами письмом.');
        }

        // Заграница: PayPal-заявка (оплата уже сделана на paypal.me, сверка ручная).
        if ($isAbroad) {
            app(CuratorNotifier::class)->paypalClaimReceived($payment);

            $adminEmail = (string) config('services.admin.email');
            if ($adminEmail !== '') {
                Mail::to($adminEmail)->send(new PaypalClaimReceivedMail($payment));
            }

            return redirect()
                ->route('book-club.shop')
                ->with('success', 'Спасибо! Заявка на книгу получена. Мы сверим платёж и пришлём подтверждение на email.');
        }

        // РФ: Tochka/СБП — после commit. Purpose с «Заказ №{id}» обязателен:
        // иначе вебхук Tochka не найдёт платёж. Одна агрегированная позиция
        // чека на всю сумму (Q10) — название содержит книгу и доставку.
        $purpose = 'Заказ №'.$payment->id.' | Книжный клуб — книга';

        try {
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: $quote['total'],
                purpose: $purpose,
                itemName: 'Книжный клуб — книга «'.config('book_club.book_title').'» и доставка',
            );
        } catch (\Throwable $e) {
            // Любой сбой провайдера (не только ConnectionException) обязан вернуть
            // резерв: иначе остаток «съеден» заказом, который никто не оплатит.
            $this->failPayment($payment, $stock, $book, $count, 'exception', $e->getMessage());

            return back()->withInput()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        }

        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $payment->update(['transaction_id' => $response['Data']['paymentLinkId']]);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $this->failPayment($payment, $stock, $book, $count, 'http', (string) $response->status());

        return back()->withInput()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * Провал платёжной попытки: статус failed и возврат резерва ОДНОЙ
     * транзакцией — иначе обрыв между двумя записями теряет экземпляры навсегда.
     */
    private function failPayment(
        Payment $payment,
        BookStock $stock,
        Book $book,
        int $count,
        string $kind,
        string $detail,
    ): void {
        DB::transaction(function () use ($payment, $stock, $book, $count): void {
            $payment->update(['status' => 'failed']);
            $stock->release($book, $count);
        });

        Log::error('Оплата книги не создана (book_club_shop)', [
            'payment_id' => $payment->id,
            'kind' => $kind,
            'detail' => $detail,
        ]);
    }

    /** Книга витрины: первая активная запись каталога. */
    private function book(): ?Book
    {
        return Book::query()->active()->first();
    }

    /** Письмо администратору о заказе, который требует ручного шага. */
    private function notifyAdmin(BookClubOrder $order): void
    {
        $adminEmail = (string) config('services.admin.email');
        if ($adminEmail === '') {
            return;
        }

        Mail::to($adminEmail)->queue(new BookClubAdminOrderMail($order));
    }
}
