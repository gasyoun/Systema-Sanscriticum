<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePaypalClaimRequest;
use App\Mail\PaypalClaimReceivedMail;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\CuratorNotifier;
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
 * PayPal и переводит запись в paid из Filament — тогда доступ/письма/прана
 * открываются штатной логикой Payment::booted(). Это зеркало DepositController,
 * но без вызова эквайринга Точки.
 */
final class PaypalClaimController extends Controller
{
    public function show(Tariff $tariff): View
    {
        $this->abortUnlessEnabled($tariff);

        $tariff->load('course');

        return view('paypal.claim', [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'price' => (float) $tariff->price,
            'meLink' => (string) config('services.paypal.me_link'),
            'recipient' => (string) config('services.paypal.recipient'),
        ]);
    }

    public function store(StorePaypalClaimRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $this->abortUnlessEnabled($tariff);

        // Резолв пользователя — вне транзакции, может бросить ValidationException
        // (гость указал email уже существующего аккаунта → отказ).
        $user = $this->resolveUser($request);

        // Приватное хранение чека (disk 'local', НЕ public: скрин может содержать
        // личные/платёжные данные). Имя файла рандомизирует Laravel.
        $proofPath = $request->file('proof')?->store('paypal-proofs', 'local') ?: null;

        [$startBlock, $endBlock] = $this->blocksFor($tariff);

        $payment = DB::transaction(function () use ($user, $tariff, $request, $proofPath, $startBlock, $endBlock): Payment {
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
                'status' => 'pending',
                'provider' => Payment::PROVIDER_PAYPAL,
                'proof_path' => $proofPath,
                'payer_note' => $this->buildNote($request),
            ]);
        });

        // Уведомления: кураторам в Telegram + письмо админу. Google Sheet НЕ
        // трогаем — финансовая таблица наполняется только по paid-платежам
        // (PaymentObserver::isSyncable), т.е. после ручного подтверждения.
        $curators->paypalClaimReceived($payment);

        $adminEmail = (string) config('services.admin.email');
        if ($adminEmail !== '') {
            Mail::to($adminEmail)->send(new PaypalClaimReceivedMail($payment));
        }

        return redirect()
            ->route('paypal.claim.show', $tariff)
            ->with('success', 'Спасибо! Заявка на оплату через PayPal получена. Как только мы сверим платёж, откроется доступ, а пароль (для новых аккаунтов) придёт на email.');
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

    /** Свободнотекстовое примечание для админки: номер транзакции + комментарий. */
    private function buildNote(StorePaypalClaimRequest $request): string
    {
        $parts = ['PayPal-заявка'];
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
