<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyInvoiceRequest;
use App\Mail\CompanyInvoiceReceivedMail;
use App\Mail\CompanyInvoiceStudentAckMail;
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
 * Счёт на оплату для юрлица / ИП (безнал по реквизитам школы).
 *
 * Зеркало PaypalClaimController: no bank API, pending Payment, human confirms
 * in Filament after the transfer lands. Printable invoice is HTML (no PDF
 * dependency) at invoice.print — gated to the owner or admin.
 *
 * Flag: config('billing.company_invoice.enabled') ← COMPANY_INVOICE_ENABLED
 * default OFF (money-contour dark ship).
 */
final class CompanyInvoiceController extends Controller
{
    public function show(Tariff $tariff): View
    {
        $this->abortUnlessEnabled($tariff);

        $tariff->load('course');

        return view('invoice.claim', [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'price' => (float) $tariff->price,
            'legal' => config('billing.legal', []),
        ]);
    }

    public function store(StoreCompanyInvoiceRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $this->abortUnlessEnabled($tariff);

        $user = $this->resolveUser($request);
        [$startBlock, $endBlock] = $this->blocksFor($tariff);

        $claimMeta = array_filter([
            'company_name' => (string) $request->validated('company_name'),
            'inn' => (string) $request->validated('inn'),
            'kpp' => $request->validated('kpp') ? (string) $request->validated('kpp') : null,
            'legal_address' => (string) $request->validated('legal_address'),
            'contact_phone' => $request->validated('contact_phone') ? (string) $request->validated('contact_phone') : null,
        ], fn ($v) => $v !== null && $v !== '');

        $payment = DB::transaction(function () use ($user, $tariff, $request, $startBlock, $endBlock, $claimMeta): Payment {
            return Payment::create([
                'user_id' => $user->id,
                'course_id' => $tariff->course_id,
                'amount' => (float) $tariff->price,
                'tariff' => $tariff->accessKey(),
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                'status' => 'pending',
                'provider' => Payment::PROVIDER_INVOICE,
                'claim_meta' => $claimMeta,
                'payer_note' => $this->buildNote($request),
            ]);
        });

        $curators->companyInvoiceReceived($payment);

        $adminEmail = (string) config('services.admin.email');
        if ($adminEmail !== '') {
            Mail::to($adminEmail)->send(new CompanyInvoiceReceivedMail($payment));
        }

        Mail::to($user)->send(new CompanyInvoiceStudentAckMail($payment));

        return redirect()
            ->route('invoice.print', $payment)
            ->with('success', 'Счет сформирован. Скачайте или распечатайте его, оплатите по реквизитам — доступ откроем после сверки поступления, обычно в течение одного-двух рабочих дней.');
    }

    /**
     * Printable HTML invoice. Owner or admin only.
     */
    public function print(Payment $payment): View
    {
        abort_unless($payment->isCompanyInvoice(), 404);
        abort_unless(
            auth()->check() && (
                auth()->id() === $payment->user_id
                || (bool) auth()->user()?->is_admin
            ),
            403
        );

        $payment->load(['user', 'course']);

        return view('invoice.print', [
            'payment' => $payment,
            'legal' => config('billing.legal', []),
            'requisitesComplete' => $this->requisitesComplete(),
        ]);
    }

    private function abortUnlessEnabled(Tariff $tariff): void
    {
        abort_unless((bool) config('billing.company_invoice.enabled'), 404);
        abort_unless($tariff->is_active, 404, 'Тариф недоступен для покупки.');
    }

    /** @return array{0: ?int, 1: ?int} */
    private function blocksFor(Tariff $tariff): array
    {
        if ($tariff->type === 'block' && $tariff->block_number) {
            return [(int) $tariff->block_number, (int) $tariff->block_number];
        }

        return [null, null];
    }

    private function buildNote(StoreCompanyInvoiceRequest $request): string
    {
        $parts = [
            'Счёт юрлицу',
            'ИНН '.$request->validated('inn'),
            $request->validated('company_name'),
        ];
        if ($comment = $request->validated('comment')) {
            $parts[] = $comment;
        }

        return Str::limit(implode(' · ', $parts), 250, '');
    }

    private function resolveUser(StoreCompanyInvoiceRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->validated('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и запросите счет оттуда.',
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

    private function requisitesComplete(): bool
    {
        $legal = config('billing.legal', []);

        return filled($legal['name'] ?? null)
            && filled($legal['inn'] ?? null)
            && filled($legal['account'] ?? null)
            && filled($legal['bik'] ?? null)
            && filled($legal['bank_name'] ?? null);
    }
}
