<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrialRequest;
use App\Models\Course;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\TochkaPaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Покупка пробного занятия с витрины. Зеркало DepositController: создаём
 * Payment(tariff='trial'), уводим в Точку; на paid-вебхуке Payment::processTrial
 * открывает доступ к одному уроку (course->trial_lesson_id) через LessonAccessGrant.
 */
final class TrialController extends Controller
{
    public function create(StoreTrialRequest $request, Course $course, TochkaPaymentService $tochka): RedirectResponse
    {
        $amount = (float) $course->trial_price;

        // Пробное доступно, только если задана цена И выбран урок.
        abort_unless(
            $amount > 0 && $course->trial_lesson_id,
            403,
            'Пробное занятие для этого курса сейчас недоступно.'
        );

        // Резолв пользователя — вне транзакции (может бросить ValidationException).
        $user = $this->resolveUser($request);

        $payment = DB::transaction(function () use ($user, $course, $amount): Payment {
            // Lead-матчинг по email — converted_at ставится только на paid-вебхуке.
            $lead = Lead::where('email', $user->email)
                ->whereNull('converted_at')
                ->latest()
                ->first();

            return Payment::create([
                'user_id' => $user->id,
                'lead_id' => $lead?->id,
                'course_id' => $course->id,
                'amount' => $amount,
                'tariff' => 'trial',
                'status' => 'pending',
                'start_block' => null,
                'end_block' => null,
            ]);
        });

        // Purpose должен включать «Заказ №{id}» — иначе вебхук Tochka не найдёт платёж.
        $purpose = 'Заказ №'.$payment->id.' | Пробное занятие курса «'.$course->title.'»';

        try {
            // Обычная продажа услуги (не предоплата): paymentMethod/paymentObject по умолчанию.
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: $amount,
                purpose: $purpose,
                itemName: 'Пробное занятие «'.$course->title.'»',
            );
        } catch (ConnectionException $e) {
            $payment->update(['status' => 'failed']);

            Log::error('Tochka недоступна (trial)', [
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

        Log::error('Ошибка Точка Эквайринг (trial)', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * Залогиненный — текущий. Гость с НОВЫМ email — создаём аккаунт и логиним.
     * Гость с СУЩЕСТВУЮЩИМ email — отказ (защита от account takeover, как в брони).
     */
    private function resolveUser(StoreTrialRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', $request->input('email'))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и оформите пробное оттуда.',
            ]);
        }

        $user = User::create([
            'email' => $request->input('email'),
            'name' => $request->input('name'),
            'password' => Hash::make(Str::random(12)),
        ]);

        auth()->login($user);

        return $user;
    }
}
