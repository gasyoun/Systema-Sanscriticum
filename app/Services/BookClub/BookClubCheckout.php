<?php

declare(strict_types=1);

namespace App\Services\BookClub;

use App\Models\BookClubOrder;
use App\Models\Payment;
use App\Models\User;
use App\Services\AttributionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Общий чекаут «Книжного клуба» (H8412): одна реализация для витрины доставки
 * участникам кампании (/knizhny-klub/dostavka, H8195) и для магазина
 * (/knizhny-klub/kupit, H8412).
 *
 * Держит ровно те три вещи, которые не должны разъехаться между витринами:
 * резолв/создание пользователя (анти-takeover), создание денежной пары
 * Payment + BookClubOrder в одной транзакции и анти-дубль под row-lock.
 * HTTP-обвязка (валидация, rate-limit, редиректы, Tochka/PayPal) остаётся в
 * контроллерах.
 */
final class BookClubCheckout
{
    /**
     * Резолв пользователя для чекаута (зеркало MarathonController::
     * resolveUserForCheckout / PaymentController::resolveUser).
     * Залогиненный — текущий. Гость с НОВЫМ email — создаём аккаунт и логиним.
     * Гость с СУЩЕСТВУЮЩИМ email — отказ (анти-takeover).
     */
    public function resolveUser(Request $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->input('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и оформите заказ оттуда.',
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

    /** Есть ли активный (не failed) заказ по этим критериям — предпроверка. */
    public function activeDuplicateExists(array $criteria, ?int $withinMinutes = null): bool
    {
        $query = BookClubOrder::query()
            ->where($criteria)
            ->whereHas('payment', fn ($q) => $q->whereNotIn('status', ['failed']));

        if ($withinMinutes !== null) {
            $query->where('created_at', '>=', now()->subMinutes($withinMinutes));
        }

        return $query->exists();
    }

    /**
     * Создать Payment + BookClubOrder в одной транзакции с анти-дублем под
     * row-lock (паттерн H071 #2): параллельные сабмиты одного и того же заказа
     * сериализуются здесь, а не только предпроверкой контроллера.
     *
     * @param  array<string, mixed>  $paymentAttributes
     * @param  array<string, mixed>  $orderAttributes
     * @param  array<string, mixed>  $duplicateCriteria
     *
     * @throws ValidationException когда такой заказ уже есть
     */
    public function createOrder(
        array $paymentAttributes,
        array $orderAttributes,
        array $duplicateCriteria = [],
        ?int $duplicateWithinMinutes = null,
        string $duplicateField = 'order',
        string $duplicateMessage = 'Такой заказ уже оформлен — повторно оплачивать не нужно.',
    ): BookClubOrder {
        return DB::transaction(function () use (
            $paymentAttributes,
            $orderAttributes,
            $duplicateCriteria,
            $duplicateWithinMinutes,
            $duplicateField,
            $duplicateMessage,
        ): BookClubOrder {
            if ($duplicateCriteria !== []) {
                $query = BookClubOrder::query()
                    ->where($duplicateCriteria)
                    ->lockForUpdate();

                if ($duplicateWithinMinutes !== null) {
                    $query->where('created_at', '>=', now()->subMinutes($duplicateWithinMinutes));
                }

                $lockedDuplicate = $query->get()
                    ->contains(fn (BookClubOrder $order) => $order->payment && $order->payment->status !== 'failed');

                if ($lockedDuplicate) {
                    throw ValidationException::withMessages([$duplicateField => $duplicateMessage]);
                }
            }

            $payment = Payment::create($paymentAttributes);

            return BookClubOrder::create($orderAttributes + ['payment_id' => $payment->id]);
        });
    }
}
