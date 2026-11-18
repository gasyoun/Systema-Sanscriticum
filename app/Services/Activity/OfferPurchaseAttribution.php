<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * H7968 — минимальная атрибуция «клик по офферу кабинета → подтверждённая
 * оплата», default-OFF (features.cabinet_offer_attribution).
 *
 * Правило связки (явное, минимальное; НЕ причинное):
 *  - пользователь: клик и платёж одного user_id;
 *  - оффер: если клик нёс course-идентичность (data.course_id / data.course),
 *    то при заданном payment.course_id они должны совпадать — чужой курс
 *    «unrelated», не считается; клик без course-идентичности (ladder: to)
 *    связывается только по пользователю+окну;
 *  - окно: клик не старше features.cabinet_offer_attribution_window_days
 *    (default 7 сут) на момент подтверждения оплаты — старше «expired»,
 *    не считается;
 *  - состояние: платёж в Payment::PAID_STATUSES (paid/success). Unpaid/failed/
 *    refunded-before-confirmation до paid не доходят — событие не пишется.
 *
 * Берётся ПОСЛЕДНИЙ подходящий клик. Дедупликация — по payment_id: повторный
 * callback (reordered/duplicate webhook delivery) второй строки не даёт.
 * Событие offer.purchase — агрегируемые скаляры (id/счётчики/окно) без сумм,
 * контактов и прочего PII; отчёт агрегирует только COUNT/DISTINCT. Обработка
 * платежей, выдача доступа и тарифы не трогаются вовсе.
 */
final class OfferPurchaseAttribution
{
    public function __construct(private readonly ActivityTracker $tracker) {}

    /** Окно связки «клик → подтверждение оплаты», суток. */
    public static function windowDays(): int
    {
        return max(1, (int) config('features.cabinet_offer_attribution_window_days', 7));
    }

    /** Точка входа из PaymentTelemetryObserver. Никогда не бросает. */
    public function recordPaid(Payment $payment): void
    {
        try {
            $this->attribute($payment);
        } catch (\Throwable $e) {
            // Телеметрия не имеет права ломать обработку платежа.
            Log::warning('OfferPurchaseAttribution emit failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function attribute(Payment $payment): void
    {
        if (! config('features.cabinet_offer_attribution', false)) {
            return;
        }

        if (! $payment->user || ! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return;
        }

        if ($payment->is_conditional) {
            return;
        }

        $excluded = (array) config('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);
        if ($payment->tariff !== null && in_array((string) $payment->tariff, $excluded, true)) {
            return;
        }

        if ($this->alreadyAttributed($payment)) {
            return;
        }

        $click = $this->matchingClick($payment);
        if ($click === null) {
            return;
        }

        $data = $this->dataOf($click);

        $this->tracker->logEvent(
            user: $payment->user,
            session: null,
            type: ActivityEvent::OFFER_PURCHASE,
            data: [
                'payment_id' => $payment->id,
                'course_id' => $payment->course_id,
                'click_id' => $click->id,
                'offer_kind' => (string) ($data['kind'] ?? ''),
                'window_days' => self::windowDays(),
            ],
        );
    }

    /** Дедупликация по payment_id: повторный callback не даёт второй строки. */
    private function alreadyAttributed(Payment $payment): bool
    {
        $value = (string) $payment->id;

        return DB::table('activity_events')
            ->where('user_id', $payment->user_id)
            ->where('event_type', ActivityEvent::OFFER_PURCHASE)
            ->where(function ($q) use ($value) {
                // SQLite/MySQL JSON path varies; substring match is enough for flat ints.
                $q->where('event_data', 'like', '%"payment_id":'.$value.'%')
                    ->orWhere('event_data', 'like', '%"payment_id":"'.$value.'"%');
            })
            ->exists();
    }

    /** Последний подходящий клик: пользователь + оффер + окно (см. правило выше). */
    private function matchingClick(Payment $payment): ?object
    {
        $since = now()->subDays(self::windowDays());

        $clicks = DB::table('activity_events')
            ->where('user_id', $payment->user_id)
            ->where('event_type', ActivityEvent::OFFER_CLICK)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', now())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($clicks as $click) {
            if ($this->offerMatches($payment, $click)) {
                return $click;
            }
        }

        return null;
    }

    private function offerMatches(Payment $payment, object $click): bool
    {
        $data = $this->dataOf($click);
        $clickCourse = $data['course_id'] ?? $data['course'] ?? null;

        if ($clickCourse === null || $payment->course_id === null) {
            return true;
        }

        return (int) $clickCourse === (int) $payment->course_id;
    }

    /** @return array<string, mixed> */
    private function dataOf(object $click): array
    {
        $raw = $click->event_data ?? null;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }
}
