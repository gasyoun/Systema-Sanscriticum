<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ActivityEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operator sales funnel readout (H2378).
 *
 * Paid denominator = OrderPaymentConversionService (same excluded tariffs /
 * non-conditional course intent). First-party step counts = activity_events.
 * Metrika is documented as browser proxy only — never invents spend/CAC.
 */
final class SalesFunnelReportService
{
    public function __construct(
        private readonly OrderPaymentConversionService $conversion,
    ) {}

    /**
     * @return array{
     *   as_of: string,
     *   days: int,
     *   from: string,
     *   to: string,
     *   steps: array<string, array{events:int, unique_users:int, source:string, note:string}>,
     *   paid_denominator: array{orders:int, paid:int, pending:int, lost:int, conversion_pct:float|null, definition:string},
     *   rates: array<string, float|null>,
     *   denominators_print: list<string>,
     *   metrika: array{counter_id:string|null, enabled:bool, goals: list<array{name:string, type:string, condition:string}>},
     *   privacy: string
     * }
     */
    public function report(int $days = 30, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();
        $days = max(1, $days);
        $from = $asOf->copy()->subDays($days);

        $stepTypes = [
            ActivityEvent::COURSE_PAGE_VIEW,
            ActivityEvent::BEGIN_CHECKOUT,
            ActivityEvent::PAYMENT_SUCCESS,
            ActivityEvent::FIRST_CABINET_ACTION,
        ];

        // Inclusive upper bound: events are inserted with created_at = now(),
        // and asOf defaults to now() — a half-open [from, asOf) window would
        // drop every just-written fixture/event at the boundary.
        $counts = DB::table('activity_events')
            ->selectRaw('event_type, COUNT(*) AS n, COUNT(DISTINCT user_id) AS uniq')
            ->whereIn('event_type', $stepTypes)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $asOf)
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');

        $notes = [
            ActivityEvent::COURSE_PAGE_VIEW => 'Auth users only (activity_events.user_id NOT NULL). Guests → Metrika course_page_view.',
            ActivityEvent::BEGIN_CHECKOUT => 'Auth users only. Guests → Metrika begin_checkout on /checkout/{tariff}.',
            ActivityEvent::PAYMENT_SUCCESS => 'First-party paid course-intent rows (excludes conditional + conversion.excluded_tariffs).',
            ActivityEvent::FIRST_CABINET_ACTION => 'Once per user ever; first cabinet.home.view / lesson_open surface.',
        ];

        $steps = [];
        foreach ($stepTypes as $type) {
            $row = $counts->get($type);
            $steps[$type] = [
                'events' => (int) ($row->n ?? 0),
                'unique_users' => (int) ($row->uniq ?? 0),
                'source' => 'activity_events',
                'note' => $notes[$type],
            ];
        }

        $baseline = $this->conversion->dozhimBaseline($asOf, [$days]);
        $rateA = $baseline['windows'][0]['rate_a'];

        $paidDenom = [
            'orders' => $rateA['orders'],
            'paid' => $rateA['paid'],
            'pending' => $rateA['pending'],
            'lost' => $rateA['lost'],
            'conversion_pct' => $rateA['conversion_pct'],
            'definition' => $baseline['zayavka_definition'],
        ];

        $page = $steps[ActivityEvent::COURSE_PAGE_VIEW]['unique_users'];
        $checkout = $steps[ActivityEvent::BEGIN_CHECKOUT]['unique_users'];
        $payFp = $steps[ActivityEvent::PAYMENT_SUCCESS]['unique_users'];
        $cabinet = $steps[ActivityEvent::FIRST_CABINET_ACTION]['unique_users'];
        $paidCanon = $paidDenom['paid'];
        $ordersCanon = $paidDenom['orders'];

        $rates = [
            'auth_page_to_checkout_pct' => $this->pct($checkout, $page),
            'auth_checkout_to_fp_paid_pct' => $this->pct($payFp, $checkout),
            'canonical_order_to_paid_pct' => $paidDenom['conversion_pct'],
            'paid_to_first_cabinet_pct' => $this->pct($cabinet, $paidCanon),
        ];

        $counter = config('analytics.metrika.shop_counter_id');
        $enabled = (bool) config('analytics.metrika.enabled', true);

        return [
            'as_of' => $asOf->toDateTimeString(),
            'days' => $days,
            'from' => $from->toDateTimeString(),
            'to' => $asOf->toDateTimeString(),
            'steps' => $steps,
            'paid_denominator' => $paidDenom,
            'rates' => $rates,
            'denominators_print' => [
                'course_page_view unique_users (auth) = denominator for auth page→checkout',
                'begin_checkout unique_users (auth) = denominator for auth checkout→fp paid',
                "canonical orders (n={$ordersCanon}) = Payment not conditional, tariff not in conversion.excluded_tariffs; cohort by payments.created_at",
                "canonical paid (n={$paidCanon}) = same cohort with status in paid|success — source of truth for money",
                'first_cabinet_action unique_users / canonical paid = activation proxy',
            ],
            'metrika' => [
                'counter_id' => $counter ? (string) $counter : null,
                'enabled' => $enabled,
                'goals' => [
                    ['name' => 'course_page_view', 'type' => 'action', 'condition' => 'reachGoal course_page_view (JS on /k/{slug})'],
                    ['name' => 'begin_checkout', 'type' => 'action', 'condition' => 'reachGoal begin_checkout (JS on /checkout/{id})'],
                    ['name' => 'payment_success', 'type' => 'action', 'condition' => 'reachGoal payment_success (JS on /payment/success)'],
                    ['name' => 'Открыл карточку курса (legacy URL)', 'type' => 'url', 'condition' => 'BROKEN until condition contains /k/ (was /online/kursy/)'],
                    ['name' => 'Начал оформление (checkout)', 'type' => 'url', 'condition' => 'contain /checkout — needs shop Metrika tag'],
                ],
            ],
            'privacy' => 'Aggregate counts only; no person-level export in this report.',
        ];
    }

    private function pct(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 1);
    }
}
