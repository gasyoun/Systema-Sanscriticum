<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipTier;
use App\Services\Membership\MembershipPublicFeed;
use App\Services\Membership\PrivateArchiveEligibility;
use Illuminate\Console\Command;

final class VerifyMembershipCommerce extends Command
{
    protected $signature = 'membership:commerce-verify {--json : print the canonical feed after verification}';

    protected $description = 'Verify H2745 feed schema, commercial parity inputs, private isolation, analytics flags and Top hold';

    public function handle(MembershipPublicFeed $feedService): int
    {
        $feed = $feedService->build();
        $errors = [];
        $ids = [];
        $privateSlugs = PrivateArchiveEligibility::privateOfferSlugs();

        foreach ($feed['offers'] as $offer) {
            foreach (['id', 'starts_on', 'status', 'price_rub', 'tariff_id', 'checkout_urls'] as $field) {
                if (! array_key_exists($field, $offer)) {
                    $errors[] = "{$offer['id']}: missing {$field}";
                }
            }
            if (isset($ids[$offer['id']])) {
                $errors[] = "duplicate offer id {$offer['id']}";
            }
            $ids[$offer['id']] = true;

            foreach (['samskrte', 'samskrtam'] as $source) {
                if (! str_contains((string) ($offer['checkout_urls'][$source] ?? ''), "source_site={$source}")) {
                    $errors[] = "{$offer['id']}: checkout missing {$source} source tag";
                }
            }
            if (in_array($offer['course_slug'], $privateSlugs, true)) {
                $errors[] = "{$offer['id']}: private archive leaked into public feed";
            }
            if (! (bool) config('features.membership_top', false) && $offer['tier'] === MembershipTier::Top->value) {
                $errors[] = "{$offer['id']}: Top leaked while go/no-go is on hold";
            }
        }

        $encoded = json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['email', 'phone', 'telegram', 'user_id', 'payment_id'] as $piiKey) {
            if (str_contains($encoded, '"'.$piiKey.'"')) {
                $errors[] = "public feed contains forbidden identity key {$piiKey}";
            }
        }

        $this->table(['gate', 'result'], [
            ['schema', $feed['schema']],
            ['version', $feed['version']],
            ['offers', count($feed['offers'])],
            ['private slugs in feed', '0 expected'],
            ['Top checkout', (bool) config('features.membership_top', false) ? 'ENABLED — human go/no-go required' : 'HOLD (correct)'],
            ['analytics', (bool) config('features.membership_funnel_analytics', false) ? 'enabled' : 'dark'],
        ]);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $this->info('H2745 commerce verification: PASS');

        return self::SUCCESS;
    }
}
