<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePaypalClaimRequest;
use App\Mail\PaypalClaimReceivedMail;
use App\Mail\PaypalClaimStudentAckMail;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\CuratorNotifier;
use App\Services\Payments\PaypalForeignPriceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Полу-интегрированная оплата из-за рубежа (PayPal).
 *
 * Автосписания нет: студент платит на paypal.me школы, затем подаёт здесь
 * уведомление. Оно ложится ОБЫЧНЫМ Payment со status=pending и provider='paypal'
 * — доступ НЕ открывается (pending не открывает ничего). Админ сверяет платёж в
 * personal PayPal (не business API) по полям from / date / amount и переводит
 * запись в paid из Filament — тогда доступ/письма/прана открываются штатной
 * логикой Payment::booted(). Это зеркало DepositController, но без вызова
 * эквайринга Точки.
 */
final class PaypalClaimController extends Controller
{
    /**
     * H3990: режим доплаты (разовая акция, MG 02-09-2026 — отдельный тариф
     * НЕ заводим). Та же форма, но с фиксированной доплатой €22/$26 (правило
     * округления рулинга 02-09: 2 000 ₽ → €22/$26) и специальной проводкой:
     * закрывает уже существующий ОТКРЫТЫЙ счёт-доплату 2 000 ₽ этого ученика
     * по курсу тарифа, а НЕ создаёт строку полной цены блока.
     */
    public const SUPPLEMENT_EUR = 22.0;

    public const SUPPLEMENT_USD = 26.0;

    public const SUPPLEMENT_RUB = 2000.0;

    public function showSupplement(Tariff $tariff, PaypalForeignPriceService $prices): View
    {
        return $this->show($tariff, $prices, true);
    }

    public function show(Tariff $tariff, PaypalForeignPriceService $prices, bool $supplement = false): View
    {
        $this->abortUnlessEnabled($tariff);

        $tariff->load('course');

        // MG 23-08-2026: рублевую цену на форме не показываем (в PayPal платят
        // только EUR/USD и дороже рублевых).
        $foreignPrice = $supplement
            ? ['eur' => self::SUPPLEMENT_EUR, 'usd' => self::SUPPLEMENT_USD, 'markup_applied' => false]
            : (config('features.paypal_fixed_price_list')
            ? $this->fixedForeignPrice($tariff, $prices)
            : $this->legacyForeignPrice($tariff));

        return view('paypal.claim', [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'price' => (float) $tariff->price,
            'foreignPrice' => $foreignPrice,
            'isSupplement' => $supplement,
            'meLink' => (string) config('services.paypal.me_link'),
            'recipient' => (string) config('services.paypal.recipient'),
        ]);
    }

    public function store(StorePaypalClaimRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $this->abortUnlessEnabled($tariff);

        if ($request->boolean('supplement_mode')) {
            return $this->storeSupplement($request, $tariff, $curators);
        }

        // Ruling 22-08-2026: заявка СУЩЕСТВУЮЩЕГО ученика (вошедшего в кабинет)
        // сразу paid — доступ/финансы открываются немедленно штатным
        // Payment::booted(), сверка выборочная и пост-фактум. Гость с новым
        // email идет по-старому: pending → ручная сверка в Filament.
        // Флаг читаем ДО resolveUser: он логинит только что созданного гостя,
        // и после него auth()->check() уже не отличит своего от нового.
        $trusted = auth()->check()
            && (bool) config('services.paypal.trust_existing_students', true);

        // Резолв пользователя — вне транзакции, может бросить ValidationException
        // (гость указал email уже существующего аккаунта → отказ).
        $user = $this->resolveUser($request);

        // Приватное хранение чека (disk 'local', НЕ public: скрин может содержать
        // личные/платёжные данные). Имя файла рандомизирует Laravel.
        $proofPath = $request->file('proof')?->store('paypal-proofs', 'local') ?: null;

        [$startBlock, $endBlock] = $this->blocksFor($tariff);

        $claimMeta = [
            'paypal_payer' => (string) $request->validated('paypal_payer'),
            'paid_on' => (string) $request->validated('paid_on'),
        ];
        if ($txn = $request->validated('paypal_txn')) {
            $claimMeta['txn'] = (string) $txn;
        }
        if ($trusted) {
            $claimMeta['auto_trusted'] = true;
            $claimMeta['trusted_at'] = now()->toIso8601String();
        }

        $payment = DB::transaction(function () use ($user, $tariff, $request, $proofPath, $startBlock, $endBlock, $claimMeta, $trusted): Payment {
            return Payment::create([
                'user_id' => $user->id,
                'course_id' => $tariff->course_id,
                // Рублёвый номинал тарифа — учётная сумма (выручка/ЗП). Реально
                // уплаченную валютную сумму кладём справочно в foreign_* (в
                // расчётах не участвует — см. Payment::foreignAmountLabel).
                'amount' => (float) $tariff->price,
                'foreign_amount' => (float) $request->validated('foreign_amount'),
                'foreign_currency' => $request->validated('foreign_currency'),
                'tariff' => $tariff->accessKey(),
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                // trusted → сразу paid: fireOnPaid на created открывает доступ,
                // письма, прану и проводит сумму по учёту без ручного шага.
                'status' => $trusted ? 'paid' : 'pending',
                'provider' => Payment::PROVIDER_PAYPAL,
                'proof_path' => $proofPath,
                'claim_meta' => $claimMeta,
                'payer_note' => $this->buildNote($request),
            ]);
        });

        // Уведомления: кураторам в Telegram + письмо админу — в ОБЕИХ ветках:
        // для trusted это вход выборочной сверки, для pending — сигнал ручной
        // проверки. Google Sheet НЕ трогаем руками — он наполняется по
        // paid-платежам (PaymentObserver::isSyncable), т.е. сразу для trusted.
        $curators->paypalClaimReceived($payment);

        $adminEmail = (string) config('services.admin.email');
        if ($adminEmail !== '') {
            Mail::to($adminEmail)->send(new PaypalClaimReceivedMail($payment));
        }

        // H1292: подтверждение студенту — до него подавший заявку не получал
        // ничего и не знал, дошла ли она. Админское письмо выше не меняется.
        Mail::to($user)->send(new PaypalClaimStudentAckMail($payment));

        $success = $trusted
            ? 'Спасибо, заявка получена — доступ к курсу открыт. Подтверждение с деталями уходит на ваш email.'
            : 'Спасибо, заявка получена — подтверждение уже уходит на ваш email. Мы сверим платеж, обычно в течение одного рабочего дня, и откроем доступ; для нового аккаунта пароль придет на email.';

        return redirect()
            ->route('paypal.claim.show', $tariff)
            ->with('success', $success);
    }

    /**
     * H3990: проводка доплаты. ВАЖНО (money-contour):
     *  1. Сумма обязана совпасть с объявленной доплатой (€22/$26, допуск 0.5 —
     *     дроби в paste-парсере); иначе валидационный отказ, «пол-блока по цене
     *     доплаты» закрыть нельзя.
     *  2. Открытый счёт-доплата (pending, 2 000 ₽, этот user+course) помечается
     *     paid БЕЗ model-событий (fireOnPaid granting access по чужому tariff-
     *     ключу недопустим); сверка выборочная пост-фактум — как у trusted-заявок.
     *  3. Если счёта нет — создаётся pending-строка доплаты 2 000 ₽ (никогда
     *     не 8 000 и никогда не trusted-paid), доступ не открывается.
     */
    private function storeSupplement(StorePaypalClaimRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $expected = $request->validated('foreign_currency') === 'USD'
            ? self::SUPPLEMENT_USD
            : self::SUPPLEMENT_EUR;

        if (abs((float) $request->validated('foreign_amount') - $expected) > 0.5) {
            throw ValidationException::withMessages([
                'foreign_amount' => 'Доплата за блок — '.$expected.' '.($request->validated('foreign_currency') === 'USD' ? '$' : '€').'. Если платите полную стоимость блока, используйте обычную форму оплаты.',
            ]);
        }

        $user = $this->resolveUser($request);

        $proofPath = $request->file('proof')?->store('paypal-proofs', 'local') ?: null;

        $invoice = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $tariff->course_id)
            ->where('amount', self::SUPPLEMENT_RUB)
            ->where('status', 'pending')
            ->orderBy('id')
            ->first();

        if ($invoice !== null) {
            $claimMeta = array_filter([
                'paypal_payer' => (string) $request->validated('paypal_payer'),
                'paid_on' => (string) $request->validated('paid_on'),
                'txn' => $request->validated('paypal_txn'),
                'supplement' => true,
                'supplement_tariff_id' => $tariff->id,
                'proof_path' => $proofPath,
            ], fn ($v) => $v !== null && $v !== '');

            // withoutEvents: инвойс — проводка долга, не покупка. fireOnPaid по
            // строке с неканоническим tariff-ключом выдал бы доступ/энролл
            // повторно; закрытие долга этого не требует.
            Payment::withoutEvents(function () use ($invoice, $request, $claimMeta): void {
                $invoice->forceFill([
                    'status' => 'paid',
                    'foreign_amount' => (float) $request->validated('foreign_amount'),
                    'foreign_currency' => $request->validated('foreign_currency'),
                    'claim_meta' => $claimMeta,
                    'provider' => Payment::PROVIDER_PAYPAL,
                    'payer_note' => Str::limit(trim(($invoice->payer_note ?? '').' · PayPal-доплата from: '
                        .$request->validated('paypal_payer').' · paid_on: '.$request->validated('paid_on')
                        .($request->validated('paypal_txn') ? ' · txn: '.$request->validated('paypal_txn') : '')), 250, ''),
                ])->save();
                $invoice->refresh();
            });

            $curators->paypalClaimReceived($invoice);
            Mail::to($user)->send(new PaypalClaimStudentAckMail($invoice));

            return redirect()
                ->route('paypal.claim.show', $tariff)
                ->with('success', 'Спасибо, доплата получена — счёт закрыт. Подтверждение уходит на ваш email.');
        }

        // Счёта нет: pending-строка доплаты (не trusted), доступ не открывается.
        $payment = DB::transaction(function () use ($user, $tariff, $request, $proofPath): Payment {
            return Payment::create([
                'user_id' => $user->id,
                'course_id' => $tariff->course_id,
                'amount' => self::SUPPLEMENT_RUB,
                'foreign_amount' => (float) $request->validated('foreign_amount'),
                'foreign_currency' => $request->validated('foreign_currency'),
                'tariff' => $tariff->accessKey(),
                'start_block' => null,
                'end_block' => null,
                'status' => 'pending',
                'provider' => Payment::PROVIDER_PAYPAL,
                'proof_path' => $proofPath,
                'claim_meta' => ['supplement' => true, 'supplement_tariff_id' => $tariff->id,
                    'paypal_payer' => (string) $request->validated('paypal_payer'),
                    'paid_on' => (string) $request->validated('paid_on')],
                'payer_note' => 'PayPal-доплата (счёт не найден) · from: '.$request->validated('paypal_payer')
                    .' · paid_on: '.$request->validated('paid_on'),
            ]);
        });

        $curators->paypalClaimReceived($payment);
        Mail::to($user)->send(new PaypalClaimStudentAckMail($payment));

        return redirect()
            ->route('paypal.claim.show', $tariff)
            ->with('success', 'Спасибо, заявка о доплате получена — мы сверим платеж и закроем счёт.');
    }

    /** Pre-H3821 behavior: manual config array, block tariffs only. Unchanged while the flag is dark. */
    private function legacyForeignPrice(Tariff $tariff): ?array
    {
        if ($tariff->type !== 'block') {
            return null;
        }

        $fp = config('services.paypal.foreign_block_prices')[$tariff->course_id] ?? null;

        return is_array($fp) && isset($fp['eur'], $fp['usd']) ? $fp : null;
    }

    /**
     * H3821: published fixed price for ANY tariff type, with the
     * student_discounts-active carve-out (no 8% markup for that payer).
     */
    private function fixedForeignPrice(Tariff $tariff, PaypalForeignPriceService $prices): ?array
    {
        $user = auth()->user();

        $eur = $prices->priceFor($tariff, 'EUR', $user);
        $usd = $prices->priceFor($tariff, 'USD', $user);

        if (! $eur || ! $usd) {
            return null;
        }

        return [
            'eur' => $eur['price'],
            'usd' => $usd['price'],
            'markup_applied' => $eur['markup_applied'],
        ];
    }

    private function abortUnlessEnabled(Tariff $tariff): void
    {
        abort_unless((bool) config('services.paypal.enabled'), 404);
        abort_unless($tariff->is_active, 404, 'Тариф недоступен для покупки.');
    }

    /**
     * Диапазон блоков для поблочного тарифа (для отчёта «Участники по блокам»
     * и подписи). Не-блочный тариф ('full') — без диапазона.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function blocksFor(Tariff $tariff): array
    {
        if ($tariff->type === 'block' && $tariff->block_number) {
            return [(int) $tariff->block_number, (int) $tariff->block_number];
        }

        return [null, null];
    }

    /** Свободнотекстовое примечание для админки: from + date + txn + comment. */
    private function buildNote(StorePaypalClaimRequest $request): string
    {
        $parts = ['PayPal-заявка'];
        $parts[] = 'from: '.$request->validated('paypal_payer');
        $parts[] = 'paid_on: '.$request->validated('paid_on');
        if ($txn = $request->validated('paypal_txn')) {
            $parts[] = 'txn: '.$txn;
        }
        if ($comment = $request->validated('comment')) {
            $parts[] = $comment;
        }

        // payer_note — string(255); режем с запасом.
        return Str::limit(implode(' · ', $parts), 250, '');
    }

    /**
     * Зеркало DepositController::resolveUser — гость с НОВЫМ email получает
     * аккаунт и логинится, гость с СУЩЕСТВУЮЩИМ email отклоняется (иначе
     * классический account takeover через публичную форму).
     */
    private function resolveUser(StorePaypalClaimRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->validated('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и подайте заявку оттуда.',
            ]);
        }

        $user = User::create([
            'email' => $request->validated('email'),
            'name' => $request->validated('name'),
            'password' => Hash::make(Str::random(12)),
        ]);

        app(AttributionService::class)->applyToNewUser($user);

        auth()->login($user);

        return $user;
    }
}
