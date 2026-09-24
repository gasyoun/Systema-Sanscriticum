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
use App\Services\Payments\PaypalClaimAmountCheck;
use App\Services\Payments\PaypalForeignPriceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
        //
        // H5083 (remediation confirmed H5046): «существующий» — это ФАКТ
        // ученика, а не presence-сессия: isEstablishedClaimStudent() требует
        // возраст аккаунта ≥ 7 дней ИЛИ проведённый (paid) платёж. Голый
        // auth()->check() доверял сессии, которую сама эта публичная форма
        // наминтила минуту назад — второй POST того же гостя уходил сразу
        // в paid без денег (Nv06 two-POST bootstrap).
        $trusted = auth()->check()
            && auth()->user()->isEstablishedClaimStudent()
            && (bool) config('services.paypal.trust_existing_students', true);

        // Резолв пользователя — вне транзакции, может бросить ValidationException
        // (гость указал email уже существующего аккаунта → отказ).
        $user = $this->resolveUser($request);

        // H5007 (audit H2): идемпотентность заявки. До этого N отправок формы =
        // N paid-платежей (trusted) с N строками выручки/праны/реферала — между
        // ними стоял только throttle:5,1. Дубль = тот же ученик, тот же тариф,
        // уже pending/paid PayPal-платёж с тем же txn (или без txn — поданный
        // сегодня же). Отказ валидацией, ничего не создаём.
        $wave = (bool) config('features.payment_fix_wave1');
        $replayKey = null;
        $amountCheck = null;
        $accessBlocked = false;
        if ($wave) {
            $this->rejectDuplicateClaim($user, $tariff, (string) ($request->validated('paypal_txn') ?? ''));

            // H5442 (P0, D4): стабильный ключ повтора — unique-индекс
            // payments.claim_replay_key, а не «тот же день».
            $replayKey = PaypalClaimAmountCheck::replayKey(
                (int) $user->id,
                (int) $tariff->id,
                $request->validated('paypal_txn'),
                (string) $request->validated('paid_on'),
                (string) $request->validated('foreign_currency'),
                (float) $request->validated('foreign_amount'),
            );
            $this->rejectReplayedClaim($user, $tariff, $replayKey);

            // H5442 (P0, D4/D20): автоподтверждение только при ожидаемой валюте
            // и сумме; ±5% засчитывается, больше — pending.
            $amountCheck = app(PaypalClaimAmountCheck::class)->check(
                $tariff,
                (string) $request->validated('foreign_currency'),
                (float) $request->validated('foreign_amount'),
                $user,
            );

            // H5442 (P0 п.4, fail-closed): курс без групп доступа — оплату
            // нельзя провести с доступом. Не 500 и не «paid без доступа»:
            // заявка ложится pending с типизированной причиной, деньги-факт
            // сохранён для ручной сверки.
            $accessBlocked = $tariff->course !== null && ! $tariff->course->groups()->exists();

            if ($trusted && (! $amountCheck['auto_confirm'] || $accessBlocked)) {
                $trusted = false;
            }
        }

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
        if ($amountCheck !== null) {
            $claimMeta['amount_check'] = $amountCheck + ['checked_at' => now()->toIso8601String()];
        }
        if ($accessBlocked) {
            $claimMeta['reconciliation_exception'] = 'no_access_groups';
        }

        try {
            $payment = $this->createClaimPayment($user, $tariff, $request, $proofPath, $startBlock, $endBlock, $claimMeta, $trusted, $replayKey);
        } catch (UniqueConstraintViolationException $e) {
            // H5442: гонка двух одновременных submit'ов с одним ключом —
            // unique-индекс claim_replay_key отбил второй; отказ, не 500.
            $this->rejectReplayedClaim($user, $tariff, (string) $replayKey);

            throw $e;
        }

        return $this->afterClaimStored($payment, $user, $tariff, $curators, $trusted, $amountCheck);
    }

    /**
     * @param  array<string, mixed>  $claimMeta
     */
    private function createClaimPayment(User $user, Tariff $tariff, StorePaypalClaimRequest $request, ?string $proofPath, ?int $startBlock, ?int $endBlock, array $claimMeta, bool $trusted, ?string $replayKey): Payment
    {
        return DB::transaction(function () use ($user, $tariff, $request, $proofPath, $startBlock, $endBlock, $claimMeta, $trusted, $replayKey): Payment {
            return Payment::create([
                'claim_replay_key' => $replayKey,
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
    }

    /**
     * @param  array<string, mixed>|null  $amountCheck
     */
    private function afterClaimStored(Payment $payment, User $user, Tariff $tariff, CuratorNotifier $curators, bool $trusted, ?array $amountCheck): RedirectResponse
    {
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
        if ($trusted && ($amountCheck['notify_underpayment'] ?? false)) {
            $success .= ' '.self::underpaymentNotice($amountCheck);
        }

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
            // H4077: живой кейс — студент перевёл одной суммой доплату и следующий
            // блок (112 = 22+90) и получил отказ без объяснения, что делать дальше.
            // Инвариант суммы не ослабляем — объясняем путь.
            throw ValidationException::withMessages([
                'foreign_amount' => 'Доплата за блок — ровно '.$expected.' '
                    .($request->validated('foreign_currency') === 'USD' ? '$' : '€')
                    .'. Укажите в поле суммы именно это число. Если перевели одной суммой доплату и следующий блок — отправьте эту форму, указав сумму '.$expected.', и напишите нам в Telegram (t.me/rusamskrtam): остаток зачтём за следующий блок. Полную стоимость блока оформляйте обычной формой оплаты.',
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

    /**
     * H5007 (audit H2): вторая заявка того же ученика по тому же тарифу — с тем же
     * PayPal txn, либо без txn, но поданная в тот же день, пока первая ещё
     * pending/paid — отклоняется. Ничего не пишем: доступ/выручка/прана по
     * первой заявке уже проведены штатно.
     */
    private function rejectDuplicateClaim(User $user, Tariff $tariff, string $txn): void
    {
        $query = Payment::query()
            ->where('provider', Payment::PROVIDER_PAYPAL)
            ->where('user_id', $user->id)
            ->where('course_id', $tariff->course_id)
            ->where('tariff', $tariff->accessKey())
            ->whereIn('status', ['pending', 'paid']);

        $txn = trim($txn);
        if ($txn !== '') {
            $query->where('claim_meta->txn', $txn);
        } else {
            $query->where('created_at', '>=', now()->startOfDay());
        }

        $duplicate = $query->orderBy('id')->first();
        if ($duplicate === null) {
            return;
        }

        Log::info('paypal_claim.duplicate_rejected', [
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'existing_payment_id' => $duplicate->id,
            'txn' => $txn !== '' ? $txn : null,
        ]);

        throw ValidationException::withMessages([
            'paypal_txn' => 'Эта заявка уже получена'
                .($txn !== '' ? ' (транзакция '.$txn.')' : ' сегодня')
                .' — повторно отправлять не нужно. Если это другой платёж, укажите его номер транзакции PayPal.',
        ]);
    }

    /**
     * H5442 (P0, D4): повтор той же заявки по стабильному ключу. Проверка до
     * записи даёт понятный отказ; unique-индекс claim_replay_key страхует гонку.
     */
    private function rejectReplayedClaim(User $user, Tariff $tariff, string $replayKey): void
    {
        $existing = Payment::query()->where('claim_replay_key', $replayKey)->first();
        if ($existing === null) {
            return;
        }

        Log::info('paypal_claim.replay_rejected', [
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'existing_payment_id' => $existing->id,
        ]);

        throw ValidationException::withMessages([
            'paypal_txn' => 'Эта заявка уже получена — повторно отправлять не нужно. Если это другой платёж, укажите его номер транзакции PayPal; если что-то не так — напишите нам в Telegram (t.me/rusamskrtam).',
        ]);
    }

    /**
     * H5442 (D4): текст уведомления о недоплате в пределах 5% — засчитано,
     * разницу добавить к следующему платежу.
     *
     * @param  array{currency:string, diff:?float}  $amountCheck
     */
    public static function underpaymentNotice(array $amountCheck): string
    {
        $symbol = ($amountCheck['currency'] ?? 'EUR') === 'USD' ? '$' : '€';
        $diff = number_format(abs((float) ($amountCheck['diff'] ?? 0)), 2, '.', ' ');

        return 'Сумма перевода меньше цены на '.$diff.' '.$symbol.' — оплату мы засчитали, но, пожалуйста, добавьте эту разницу к следующему платежу.';
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
