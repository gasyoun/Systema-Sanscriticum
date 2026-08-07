<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * First-party sales-funnel telemetry (H2378).
 *
 * Writes into activity_events only (no second analytics store). Guests cannot
 * be stored (user_id NOT NULL) — browser Metrika covers the anonymous path.
 * Paid denominators stay owned by OrderPaymentConversionService.
 */
final class FunnelTelemetry
{
    public function __construct(private readonly ActivityTracker $tracker) {}

    public function emitCoursePageView(User $user, int $courseId, ?Request $request = null): void
    {
        $this->emitOncePerDay(
            user: $user,
            type: ActivityEvent::COURSE_PAGE_VIEW,
            dayKeyField: 'course_id',
            dayKeyValue: $courseId,
            data: ['course_id' => $courseId],
            request: $request,
        );
    }

    public function emitBeginCheckout(User $user, int $tariffId, ?int $courseId = null, ?Request $request = null): void
    {
        $data = ['tariff_id' => $tariffId];
        if ($courseId !== null) {
            $data['course_id'] = $courseId;
        }

        $this->emitOncePerDay(
            user: $user,
            type: ActivityEvent::BEGIN_CHECKOUT,
            dayKeyField: 'tariff_id',
            dayKeyValue: $tariffId,
            data: $data,
            request: $request,
        );
    }

    /**
     * Qualifying paid course intent only — same exclusion idea as conversion.php
     * (conditional / excluded tariffs skipped). Once per payment_id.
     */
    public function emitPaymentSuccess(User $user, Payment $payment, ?Request $request = null): void
    {
        if ($payment->is_conditional) {
            return;
        }

        $excluded = (array) config('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);
        if ($payment->tariff !== null && in_array((string) $payment->tariff, $excluded, true)) {
            return;
        }

        if (! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return;
        }

        if ($this->existsWithDataKey($user->id, ActivityEvent::PAYMENT_SUCCESS, 'payment_id', (string) $payment->id)) {
            return;
        }

        $this->safeEmit($user, ActivityEvent::PAYMENT_SUCCESS, [
            'payment_id' => $payment->id,
            'course_id' => $payment->course_id,
            'amount' => (string) $payment->amount,
            'tariff' => $payment->tariff,
        ], $request);
    }

    /** Once per user ever — first meaningful cabinet surface. */
    public function emitFirstCabinetAction(User $user, string $surface, ?Request $request = null): void
    {
        if ($this->existsType($user->id, ActivityEvent::FIRST_CABINET_ACTION)) {
            return;
        }

        $this->safeEmit($user, ActivityEvent::FIRST_CABINET_ACTION, [
            'surface' => $surface,
        ], $request);
    }

    private function emitOncePerDay(
        User $user,
        string $type,
        string $dayKeyField,
        int $dayKeyValue,
        array $data,
        ?Request $request,
    ): void {
        $start = now()->startOfDay();
        $end = now()->endOfDay();

        $already = DB::table('activity_events')
            ->where('user_id', $user->id)
            ->where('event_type', $type)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->where(function ($q) use ($dayKeyField, $dayKeyValue) {
                // SQLite/MySQL JSON path varies; substring match is enough for flat ints.
                $q->where('event_data', 'like', '%"'.$dayKeyField.'":'.$dayKeyValue.'%')
                    ->orWhere('event_data', 'like', '%"'.$dayKeyField.'":"'.$dayKeyValue.'"%');
            })
            ->exists();

        if ($already) {
            return;
        }

        $this->safeEmit($user, $type, $data, $request);
    }

    private function existsWithDataKey(int $userId, string $type, string $key, string $value): bool
    {
        return DB::table('activity_events')
            ->where('user_id', $userId)
            ->where('event_type', $type)
            ->where(function ($q) use ($key, $value) {
                $q->where('event_data', 'like', '%"'.$key.'":'.$value.'%')
                    ->orWhere('event_data', 'like', '%"'.$key.'":"'.$value.'"%');
            })
            ->exists();
    }

    private function existsType(int $userId, string $type): bool
    {
        return DB::table('activity_events')
            ->where('user_id', $userId)
            ->where('event_type', $type)
            ->exists();
    }

    private function safeEmit(User $user, string $type, array $data, ?Request $request): void
    {
        try {
            $this->tracker->logEvent(
                user: $user,
                session: null,
                type: $type,
                data: $data,
                request: $request,
            );
        } catch (\Throwable $e) {
            Log::warning('FunnelTelemetry emit failed', [
                'user_id' => $user->id,
                'event' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
