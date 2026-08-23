<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDonationRequest;
use App\Models\DonationGratitude;
use App\Models\Payment;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\Payments\TochkaPaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Онлайн-приём пожертвований на /mecenaty через Точку (план института N2,
 * решение MG 23-08 «Тир-1 — делай»).
 *
 * Донорская рамка без встречного пакета благ: платёж tariff=donation без
 * course_id, fireOnPaid выходит сразу (см. Payment::isDonation) — доступы,
 * членство и лид-конверсии не трогаются. Флаг institute.donations_enabled
 * default OFF: выключено = 404, страница живёт в режиме реквизитов.
 */
final class InstituteDonateController extends Controller
{
    /**
     * Страница /mecenaty: вступление, форма онлайн-взноса (при включённом
     * флаге), реквизиты и публичный реестр благодарностей (N3).
     */
    public function page(): View
    {
        return view('institute.mecenaty', [
            'gratitudes' => DonationGratitude::query()->publicList()->with('payment')->get(),
        ]);
    }

    public function donate(StoreDonationRequest $request, TochkaPaymentService $tochka): RedirectResponse
    {
        abort_unless((bool) config('institute.donations_enabled'), 404);

        $amount = (int) $request->validated('amount');

        // Благодарность (N3): согласие фиксируем вместе с платежом — строка
        // реестра появится только после фактической оплаты (processDonationGratitude).
        $claimMeta = [];
        if ($request->boolean('gratitude_consent') && filled($request->input('gratitude_name'))) {
            $claimMeta['gratitude'] = [
                'consent' => true,
                'name' => trim((string) $request->input('gratitude_name')),
                // Сумма в реестре — только по отдельной просьбе человека (MG 23-08).
                'show_amount' => $request->boolean('gratitude_amount'),
            ];
        }

        // Резолв пользователя — вне транзакции: может бросить ValidationException
        // (гость указал email уже существующего аккаунта).
        $user = $this->resolveUser($request);

        // Только запись в БД — в транзакции; HTTP-вызов Точки ПОСЛЕ commit,
        // чтобы медленный эквайринг не держал row-lock на payments
        // (тот же порядок, что у депозита).
        $payment = DB::transaction(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => $amount,
            'tariff' => 'donation',
            'status' => 'pending',
            'claim_meta' => $claimMeta,
        ]));

        // Purpose обязан содержать «Заказ №{id}» — иначе вебхук Точки
        // (WebhookController::handleTochkaWebhook, regex /Заказ №(\d+)/)
        // не найдёт платёж, и тот навсегда останется в pending.
        $purpose = 'Заказ №'.$payment->id.' | Добровольное пожертвование';

        try {
            // Признак предмета расчёта в чеке (service) зависит от итоговой
            // юр-рамки взноса (пожертвование ст. 582 vs услуга по оферте —
            // @DECIDE MG до включения флага); сейчас дефолт контура.
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: (float) $amount,
                purpose: $purpose,
                itemName: 'Добровольное пожертвование',
            );
        } catch (ConnectionException $e) {
            $payment->update(['status' => 'failed']);

            Log::error('Tochka недоступна (donation)', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        }

        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $payment->update(['transaction_id' => $response['Data']['paymentLinkId']]);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $payment->update(['status' => 'failed']);

        Log::error('Ошибка Точка Эквайринг (donation)', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * Тот же контракт безопасности, что у депозита: гость с НОВЫМ email —
     * создаём аккаунт и логиним; с СУЩЕСТВУЮЩИМ — отказ с просьбой войти
     * (анти-takeover: никто не логинится в чужой кабинет одним email).
     */
    private function resolveUser(StoreDonationRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->input('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и поддержите оттуда.',
            ]);
        }

        $user = User::create([
            'email' => $request->input('email'),
            'name' => $request->input('name'),
            'password' => Hash::make(Str::random(12)),
        ]);

        app(AttributionService::class)->applyToNewUser($user);

        auth()->login($user);

        return $user;
    }
}
