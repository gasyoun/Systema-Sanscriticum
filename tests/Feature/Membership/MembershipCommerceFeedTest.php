<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\ClubMembership;
use App\Models\Group;
use App\Models\MembershipFunnelEvent;
use App\Models\Payment;
use App\Models\PrivateArchiveAccessEvent;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Membership\MembershipFunnelAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class MembershipCommerceFeedTest extends MembershipTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.membership_public_feed', true);
        config()->set('features.membership_private_archives', true);
        config()->set('features.membership_funnel_analytics', true);
        config()->set('features.membership_tiered', true);
        config()->set('membership.private_archives.archives.yoga_sutras.enabled', true);
    }

    public function test_public_feed_is_dark_by_default(): void
    {
        config()->set('features.membership_public_feed', false);

        $this->get('/api/public/v1/autumn-membership')->assertNotFound();
        $this->get('https://samskrte.ru/osen-2026')->assertNotFound();
        $this->get('https://samskrtam.ru/courses/autumn-2026')->assertNotFound();
    }

    public function test_one_feed_drives_distinct_storefronts_with_commercial_parity_and_source_tags(): void
    {
        $this->clubTariff(1, MembershipTier::Basic);
        $this->clubTariff(3, MembershipTier::Club);
        $this->clubTariff(1, MembershipTier::Top);

        $course = Course::factory()->create([
            'title' => 'Грамматика санскрита — осень 2026',
            'slug' => 'grammar-autumn-2026',
            'is_visible' => true,
            'is_active' => true,
        ]);
        $group = Group::create([
            'name' => 'Осенняя группа',
            'status' => 'forming',
            'planned_start_date' => '2026-09-16',
        ]);
        $course->groups()->attach($group);
        Tariff::create([
            'course_id' => $course->id,
            'title' => 'Первый блок',
            'type' => 'block',
            'block_number' => 1,
            'price' => 6000,
            'is_active' => true,
        ]);

        $feed = $this->get('/api/public/v1/autumn-membership')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->json();

        $this->assertSame('systema.membership-commerce-feed.v1', $feed['schema']);
        $this->assertNotEmpty($feed['offers']);
        $this->assertNotContains('top', array_column($feed['offers'], 'tier'));
        $this->assertContains(6000, array_column($feed['offers'], 'price_rub'));
        $this->assertStringContainsString('source_site=samskrte', $feed['offers'][0]['checkout_urls']['samskrte']);
        $this->assertStringContainsString('source_site=samskrtam', $feed['offers'][0]['checkout_urls']['samskrtam']);

        $editorial = $this->get('https://samskrte.ru/osen-2026')->assertOk();
        $catalogue = $this->get('https://samskrtam.ru/courses/autumn-2026')->assertOk();
        $editorial->assertSee('редакционный календарь')->assertSee('data-storefront="samskrte"', false);
        $catalogue->assertSee('data-storefront="samskrtam"', false)->assertDontSee('редакционный календарь');

        $this->assertSame(
            $this->commercialPayloads($editorial->getContent()),
            $this->commercialPayloads($catalogue->getContent()),
        );

        $this->assertSame(2, MembershipFunnelEvent::query()
            ->where('event_name', MembershipFunnelEvent::OFFER_VIEW)
            ->where('feature', 'autumn_storefront')
            ->count());

        $this->artisan('membership:commerce-verify')
            ->expectsOutput('H2745 commerce verification: PASS')
            ->assertSuccessful();
    }

    public function test_private_archive_is_payment_derived_noindex_non_enumerated_audited_and_killable(): void
    {
        $source = Course::factory()->create(['slug' => 'eligible-yoga', 'is_visible' => true, 'is_active' => true]);
        $offer = Course::factory()->create([
            'title' => 'H2745 PRIVATE YOGA OFFER SENTINEL',
            'slug' => 'private-yoga-offer',
            'is_visible' => true,
            'is_active' => true,
        ]);
        $tariff = Tariff::create([
            'course_id' => $offer->id,
            'title' => 'Записи целиком',
            'type' => 'full',
            'price' => 12000,
            'is_active' => true,
            'is_recording' => true,
        ]);
        config()->set('membership.private_archives.archives.yoga_sutras.offer_course_slug', $offer->slug);
        config()->set('membership.private_archives.archives.yoga_sutras.eligibility_course_slugs', [$source->slug]);

        $ineligible = User::factory()->create();
        $this->actingAs($ineligible)
            ->get('/dvaram/private-archive/yoga_sutras')
            ->assertNotFound();
        $this->assertDatabaseHas('private_archive_access_events', [
            'user_id' => $ineligible->id,
            'archive_key' => 'yoga_sutras',
            'action' => 'denied',
            'reason' => 'not_eligible',
        ]);

        $eligible = User::factory()->create();
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $eligible->id,
            'course_id' => $source->id,
            'amount' => 30000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        $page = $this->actingAs($eligible)
            ->get('/dvaram/private-archive/yoga_sutras')
            ->assertOk()
            ->assertSee('noindex, nofollow', false)
            ->assertSee('source_site=private_archive', false)
            ->assertSee('archive=yoga_sutras', false);
        $page->assertSee($tariff->title);

        $this->get('/online')->assertDontSee($offer->title);
        $this->get('/k/'.$offer->slug)->assertNotFound();
        $this->get('/api/public/v1/autumn-membership')->assertJsonMissing(['course_slug' => $offer->slug]);
        $this->get('/sitemap.xml')->assertDontSee('/dvaram/private-archive/', false);
        $this->assertSame(1, PrivateArchiveAccessEvent::query()->where('action', 'viewed')->count());

        $this->artisan('membership:archive-kill yoga_sutras --reason=contract-stop')
            ->expectsOutput('KILLED yoga_sutras')
            ->assertSuccessful();
        $this->get('/dvaram/private-archive/yoga_sutras')->assertNotFound();

        $this->artisan('membership:archive-kill yoga_sutras --restore')
            ->expectsOutput('RESTORED yoga_sutras')
            ->assertSuccessful();
        $this->get('/dvaram/private-archive/yoga_sutras')->assertOk();
    }

    public function test_checkout_payment_and_lifecycle_events_keep_required_dimensions(): void
    {
        $user = User::factory()->create();
        $tariff = $this->clubTariff(3, MembershipTier::Club);

        $this->actingAs($user)->get(route('checkout.show', [
            'tariff' => $tariff,
            'source_site' => 'samskrtam',
            'campaign' => 'autumn-2026',
        ]))->assertOk();

        $checkout = MembershipFunnelEvent::query()->where('event_name', MembershipFunnelEvent::CHECKOUT)->firstOrFail();
        $this->assertSame('club', $checkout->tier);
        $this->assertSame(3, $checkout->term_months);
        $this->assertSame('samskrtam', $checkout->source_site);
        $this->assertSame($tariff->course_id, $checkout->course_id);

        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => $tariff->price,
            'tariff' => $tariff->accessKey(),
            'status' => 'paid',
            'is_conditional' => false,
        ]));
        app(MembershipFunnelAnalytics::class)->recordPayment($payment);

        $paid = MembershipFunnelEvent::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('payment', $paid->event_name);
        $this->assertSame('club', $paid->tier);
        $this->assertSame(3, $paid->term_months);
        $this->assertSame('samskrtam', $paid->source_site);
    }

    public function test_renewal_restoration_and_lapse_events_keep_tier_and_term_dimensions(): void
    {
        $user = User::factory()->create();
        $tariff = $this->clubTariff(1, MembershipTier::Basic);
        $historicPayment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => $tariff->price,
            'tariff' => $tariff->accessKey(),
            'status' => 'paid',
            'is_conditional' => false,
        ]));
        ClubMembership::create([
            'user_id' => $user->id,
            'payment_id' => $historicPayment->id,
            'tier_code' => MembershipTier::Basic,
            'term_months' => 1,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
            'grace_until' => now()->subMonth()->addDays(3),
            'grace_days' => 3,
            'revoked_at' => now()->subWeeks(3),
            'revoke_reason' => ClubMembership::REVOKE_LAPSED,
            'source' => ClubMembership::SOURCE_PAYMENT,
        ]);

        $restorationPayment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => $tariff->price,
            'tariff' => $tariff->accessKey(),
            'status' => 'paid',
            'is_conditional' => false,
        ]));
        app(MembershipFunnelAnalytics::class)->recordPayment($restorationPayment);
        $restoration = MembershipFunnelEvent::query()->where('payment_id', $restorationPayment->id)->firstOrFail();
        $this->assertSame(MembershipFunnelEvent::RESTORATION, $restoration->event_name);
        $this->assertSame('basic', $restoration->tier);
        $this->assertSame(1, $restoration->term_months);

        ClubMembership::create([
            'user_id' => $user->id,
            'payment_id' => $restorationPayment->id,
            'tier_code' => MembershipTier::Basic,
            'term_months' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'grace_until' => now()->addMonth()->addDays(3),
            'grace_days' => 3,
            'source' => ClubMembership::SOURCE_PAYMENT,
        ]);
        $renewalPayment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => $tariff->price,
            'tariff' => $tariff->accessKey(),
            'status' => 'paid',
            'is_conditional' => false,
        ]));
        app(MembershipFunnelAnalytics::class)->recordPayment($renewalPayment);
        $renewal = MembershipFunnelEvent::query()->where('payment_id', $renewalPayment->id)->firstOrFail();
        $this->assertSame(MembershipFunnelEvent::RENEWAL, $renewal->event_name);
        $this->assertSame('basic', $renewal->tier);
        $this->assertSame(1, $renewal->term_months);

        app(MembershipFunnelAnalytics::class)->recordLapse($user, 'basic', 1, 999);
        $lapse = MembershipFunnelEvent::query()->where('event_name', MembershipFunnelEvent::LAPSE)->firstOrFail();
        $this->assertSame('basic', $lapse->tier);
        $this->assertSame(1, $lapse->term_months);
        $this->assertSame('system', $lapse->source_site);
    }

    /** @return list<array<string,mixed>> */
    private function commercialPayloads(string $html): array
    {
        preg_match_all('/data-commercial=\'([^\']+)\'/', $html, $matches);

        return array_map(
            static fn (string $json): array => json_decode(html_entity_decode($json), true, flags: JSON_THROW_ON_ERROR),
            $matches[1],
        );
    }
}
