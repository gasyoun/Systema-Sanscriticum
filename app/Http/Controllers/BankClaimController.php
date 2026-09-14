<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankClaimRequest;
use App\Mail\BankClaimReceivedMail;
use App\Mail\BankClaimStudentAckMail;
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
 * Полу-интегрированная оплата банковским переводом (SEPA/SWIFT) на внешний
 * счёт получателя школы за рубежом (H3497). Зеркало PaypalClaimController без
 * PayPal-специфики: автосверки нет, платёж ложится ОБЫЧНЫМ Payment со
 * status=pending и provider='bank_sepa' — доступ НЕ открывается. Админ сверяет
 * поступление по выписке получателя (отправитель / дата / сумма / референция)
 * и переводит запись в paid из Filament. Trusted-рулинг 22-08-2026 зеркалится:
 * заявка вошедшего существующего ученика сразу paid.
 */
final class BankClaimController extends Controller
{
    public function show(Tariff $tariff): View
    {
        $this->abortUnlessEnabled($tariff);

        $tariff->load('course');

        return view('bank.claim', [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'price' => (float) $tariff->price,
            'recipientName' => (string) config('services.bank_claim.recipient_name'),
            'iban' => (string) config('services.bank_claim.iban'),
            'bic' => (string) config('services.bank_claim.bic'),
            'bankName' => (string) config('services.bank_claim.bank_name'),
        ]);
    }

    public function store(StoreBankClaimRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $this->abortUnlessEnabled($tariff);

        // Ruling 22-08-2026 (зеркало из PayPal-канала): заявка СУЩЕСТВУЮЩЕГО
        // ученика сразу paid; гость с новым email — pending → ручная сверка.
        // Флаг читаем ДО resolveUser: он логинит только что созданного гостя.
        $trusted = auth()->check()
            && (bool) config('services.bank_claim.trust_existing_students', true);

        $user = $this->resolveUser($request);

        // Приватное хранение чека/выписки (disk 'local', НЕ public).
        $proofPath = $request->file('proof')?->store('bank-proofs', 'local') ?: null;

        [$startBlock, $endBlock] = $this->blocksFor($tariff);

        $claimMeta = [
            'sender_name' => (string) $request->validated('sender_name'),
            'paid_on' => (string) $request->validated('paid_on'),
        ];
        if ($ref = $request->validated('reference')) {
            $claimMeta['reference'] = (string) $ref;
        }
        if ($trusted) {
            $claimMeta['auto_trusted'] = true;
            $claimMeta['trusted_at'] = now()->toIso8601String();
        }

        $payment = DB::transaction(function () use ($user, $tariff, $request, $proofPath, $startBlock, $endBlock, $claimMeta, $trusted): Payment {
            return Payment::create([
                'user_id' => $user->id,
                'course_id' => $tariff->course_id,
                // Рублёвый номинал тарифа — учётная сумма (выручка/ЗП); реально
                // уплаченная валютная сумма — справочно в foreign_*.
                'amount' => (float) $tariff->price,
                'foreign_amount' => (float) $request->validated('foreign_amount'),
                'foreign_currency' => $request->validated('foreign_currency'),
                'tariff' => $tariff->accessKey(),
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                'status' => $trusted ? 'paid' : 'pending',
                'provider' => Payment::PROVIDER_BANK_SEPA,
                'proof_path' => $proofPath,
                'claim_meta' => $claimMeta,
                'payer_note' => $this->buildNote($request),
            ]);
        });

        $curators->bankClaimReceived($payment);

        $adminEmail = (string) config('services.admin.email');
        if ($adminEmail !== '') {
            Mail::to($adminEmail)->send(new BankClaimReceivedMail($payment));
        }

        Mail::to($user)->send(new BankClaimStudentAckMail($payment));

        $success = $trusted
            ? 'Спасибо, заявка получена — доступ к курсу открыт. Подтверждение с деталями уходит на ваш email.'
            : 'Спасибо, заявка получена — подтверждение уже уходит на ваш email. Мы сверим поступление по выписке, обычно в течение одного рабочего дня, и откроем доступ; для нового аккаунта пароль придет на email.';

        return redirect()
            ->route('bank.claim.show', $tariff)
            ->with('success', $success);
    }

    private function abortUnlessEnabled(Tariff $tariff): void
    {
        abort_unless((bool) config('services.bank_claim.enabled'), 404);
        abort_unless($tariff->is_active, 404, 'Тариф недоступен для покупки.');
    }

    /**
     * Диапазон блоков для поблочного тарифа. Не-блочный ('full') — без диапазона.
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

    /** Свободнотекстовое примечание для админки: sender + date + reference + comment. */
    private function buildNote(StoreBankClaimRequest $request): string
    {
        $parts = ['Банковский перевод'];
        $parts[] = 'from: '.$request->validated('sender_name');
        $parts[] = 'paid_on: '.$request->validated('paid_on');
        if ($ref = $request->validated('reference')) {
            $parts[] = 'ref: '.$ref;
        }
        if ($comment = $request->validated('comment')) {
            $parts[] = $comment;
        }

        // payer_note — string(255); режем с запасом.
        return Str::limit(implode(' · ', $parts), 250, '');
    }

    /**
     * Зеркало PaypalClaimController::resolveUser — гость с НОВЫМ email получает
     * аккаунт и логинится, гость с СУЩЕСТВУЮЩИМ email отклоняется.
     */
    private function resolveUser(StoreBankClaimRequest $request): User
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
