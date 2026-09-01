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
    public function show(Tariff $tariff, PaypalForeignPriceService $prices): View
    {
        $this->abortUnlessEnabled($tariff);

        $tariff->load('course');

        // MG 23-08-2026: рублевую цену на форме не показываем (в PayPal платят
        // только EUR/USD и дороже рублевых).
        $foreignPrice = config('features.paypal_fixed_price_list')
            ? $this->fixedForeignPrice($tariff, $prices)
            : $this->legacyForeignPrice($tariff);

        return view('paypal.claim', [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'price' => (float) $tariff->price,
            'foreignPrice' => $foreignPrice,
            'meLink' => (string) config('services.paypal.me_link'),
            'recipient' => (string) config('services.paypal.recipient'),
        ]);
    }

    public function store(StorePaypalClaimRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $this->abortUnlessEnabled($tariff);

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
