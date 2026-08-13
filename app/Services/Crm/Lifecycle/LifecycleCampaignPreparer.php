<?php

declare(strict_types=1);

namespace App\Services\Crm\Lifecycle;

use App\Models\Campaign;
use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\MessageTemplate;
use InvalidArgumentException;

/**
 * CRM Wave 2 (H2484) — turn a census into draft Campaigns / FollowUpTasks.
 *
 * Never sends. Human approval is the existing CampaignResource «Отправить»
 * confirmation, which still requires `email_campaigns`.
 */
final class LifecycleCampaignPreparer
{
    public const FOLLOW_UP_PREFIX = '[lifecycle:';

    public function __construct(
        private readonly LifecycleEligibility $eligibility,
    ) {}

    /**
     * @return array{
     *   dry_run: bool,
     *   flag: bool,
     *   rules: list<array<string, mixed>>
     * }
     */
    public function run(bool $apply, ?string $onlyRule = null, bool $withFollowUps = true): array
    {
        $rules = $onlyRule === null ? LifecycleEligibility::rules() : [$onlyRule];
        foreach ($rules as $rule) {
            if (! in_array($rule, LifecycleEligibility::rules(), true)) {
                throw new InvalidArgumentException('Unknown lifecycle rule: '.$rule);
            }
        }

        $flagOn = (bool) config('features.crm_lifecycle_automation');
        $rows = [];

        foreach ($rules as $rule) {
            $census = $this->eligibility->evaluate($rule);
            $row = $census->toArray();
            $row['campaign_id'] = null;
            $row['follow_ups_created'] = 0;
            $row['applied'] = false;

            if ($apply && $flagOn) {
                $campaign = $this->upsertDraft($rule, $census);
                $row['campaign_id'] = $campaign->id;
                $row['applied'] = true;
                if ($withFollowUps) {
                    $row['follow_ups_created'] = $this->upsertFollowUps($rule, $census);
                }
            }

            $rows[] = $row;
        }

        return [
            'dry_run' => ! $apply,
            'flag' => $flagOn,
            'rules' => $rows,
        ];
    }

    public function upsertDraft(string $rule, LifecycleCensus $census): Campaign
    {
        $cfg = $this->eligibility->ruleConfig($rule);
        $version = $census->version;
        $segment = [
            'type' => 'lifecycle',
            'rule' => $rule,
            'version' => $version,
        ];

        $existing = Campaign::query()
            ->where('status', Campaign::STATUS_DRAFT)
            ->where('segment->type', 'lifecycle')
            ->where('segment->rule', $rule)
            ->where('segment->version', $version)
            ->latest('id')
            ->first();

        [$subject, $body] = $this->copyFor($rule, $cfg);

        if ($existing) {
            $existing->update([
                'subject' => $subject,
                'body_html' => $body,
                'segment' => $segment,
            ]);

            return $existing->fresh();
        }

        return Campaign::query()->create([
            'subject' => $subject,
            'body_html' => $body,
            'segment' => $segment,
            'status' => Campaign::STATUS_DRAFT,
        ]);
    }

    private function upsertFollowUps(string $rule, LifecycleCensus $census): int
    {
        $created = 0;
        $due = now()->addDays((int) config('crm_lifecycle.follow_up_due_days', 2));
        $note = self::FOLLOW_UP_PREFIX.$rule.':v'.$census->version.']';

        foreach ($census->eligibleUsers as $user) {
            $deal = Deal::query()
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->latest('id')
                ->first();
            if ($deal === null) {
                continue;
            }

            $exists = FollowUpTask::query()
                ->where('deal_id', $deal->id)
                ->whereNull('done_at')
                ->where('note', 'like', $note.'%')
                ->exists();
            if ($exists) {
                continue;
            }

            FollowUpTask::query()->create([
                'deal_id' => $deal->id,
                'type' => FollowUpTask::TYPE_MESSAGE,
                'note' => $note.' '.$this->eligibility->ruleConfig($rule)['label'],
                'due_at' => $due,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{0: string, 1: string}
     */
    private function copyFor(string $rule, array $cfg): array
    {
        $category = (string) ($cfg['template_category'] ?? '');
        if ($category !== '') {
            $template = MessageTemplate::query()->forCategory($category)->first();
            if ($template) {
                return [
                    (string) $template->title,
                    '<p>'.nl2br(e((string) $template->body)).'</p>',
                ];
            }
        }

        return [
            (string) ($cfg['subject'] ?? $rule),
            (string) ($cfg['body_html'] ?? '<p></p>'),
        ];
    }
}
