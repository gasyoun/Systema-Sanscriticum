<?php

declare(strict_types=1);

namespace App\Services\Crm\Lifecycle;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Aggregate campaign outcomes for lifecycle drafts/sends.
 * Paid count uses the qualifying Payment denominator and never writes payments.
 */
final class LifecycleOutcomeReport
{
    public function __construct(
        private readonly LifecycleEligibility $eligibility,
    ) {}

    /**
     * @return array{
     *   as_of: string,
     *   rules: list<array<string, mixed>>
     * }
     */
    public function build(): array
    {
        $rows = [];
        foreach (LifecycleEligibility::rules() as $rule) {
            $census = $this->eligibility->evaluate($rule);
            $campaignIds = Campaign::query()
                ->where('segment->type', 'lifecycle')
                ->where('segment->rule', $rule)
                ->where('segment->version', $census->version)
                ->pluck('id');

            $recipients = $campaignIds->isEmpty()
                ? collect()
                : CampaignRecipient::query()->whereIn('campaign_id', $campaignIds)->get();

            $sentUserIds = $recipients
                ->whereNotNull('sent_at')
                ->pluck('user_id')
                ->filter()
                ->unique()
                ->values();

            $paid = 0;
            if ($sentUserIds->isNotEmpty()) {
                $excluded = (array) config('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);
                $firstSent = $recipients->whereNotNull('sent_at')->min('sent_at');
                $paid = Payment::query()
                    ->whereIn('user_id', $sentUserIds)
                    ->whereIn('status', Payment::PAID_STATUSES)
                    ->where('is_conditional', false)
                    ->when($excluded !== [], fn ($q) => $q->whereNotIn('tariff', $excluded))
                    ->when($firstSent, fn ($q) => $q->where('created_at', '>=', Carbon::parse($firstSent)))
                    ->pluck('user_id')
                    ->unique()
                    ->count();
            }

            $rows[] = array_merge($census->toArray(), [
                'campaigns' => $campaignIds->count(),
                'recipients' => $recipients->count(),
                'sent' => $recipients->whereNotNull('sent_at')->count(),
                'delivered' => $recipients->whereNotNull('sent_at')->whereNull('bounced_at')->count(),
                'clicked' => $recipients->whereNotNull('clicked_at')->count(),
                'opened' => $recipients->whereNotNull('opened_at')->count(),
                'paid' => $paid,
            ]);
        }

        return [
            'as_of' => now()->toIso8601String(),
            'rules' => $rows,
        ];
    }
}
