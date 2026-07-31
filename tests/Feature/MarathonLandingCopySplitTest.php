<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonLandingCopyVariantEvent;
use App\Models\MarketingSetting;
use App\Support\MarathonLandingCopySplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H2010 — real per-visitor A/B split for MarathonLandingCopy (MG ruling
 * 31-07-2026, cutoff 2026-11-01). Covers: sticky assignment, ~50/50 split,
 * one impression per new assignment (not per reload), lead tagging, and
 * the post-cutoff fallback (no new random assignment, no fabricated
 * impression).
 *
 * Cookies are encrypted (App\Http\Middleware\EncryptCookies, no `except`
 * entry for this cookie). Read the real a/b value via
 * TestResponse::getCookie()->getValue() (auto-decrypts); feed it straight
 * back into withCookie() -- Laravel's test client (`encryptCookies=true` by
 * default) encrypts it again itself, so passing an already-encrypted raw
 * Set-Cookie value here would double-encrypt and silently fail decryption
 * on the next request.
 */
class MarathonLandingCopySplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg',
        ]);
        config(['marathon_landing_copy.ab_test_until' => '2026-11-01']);
    }

    private function decryptedVariant(TestResponse $response): string
    {
        return $response->getCookie(MarathonLandingCopySplit::COOKIE_NAME)->getValue();
    }

    /**
     * headers->getCookies() also carries the framework's session/XSRF
     * cookies -- always check ours by name, never by array position.
     */
    private function hasVariantCookie(TestResponse $response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === MarathonLandingCopySplit::COOKIE_NAME) {
                return true;
            }
        }

        return false;
    }

    public function test_first_visit_assigns_a_variant_and_records_one_impression(): void
    {
        $this->travelTo('2026-08-01 10:00:00');

        $response = $this->get(route('marathon.show'));

        $response->assertOk();
        $response->assertCookie(MarathonLandingCopySplit::COOKIE_NAME);
        $this->assertContains($this->decryptedVariant($response), ['a', 'b']);

        $this->assertSame(1, MarathonLandingCopyVariantEvent::where('event_type', 'impression')->count());
    }

    public function test_repeat_visit_with_cookie_does_not_record_a_second_impression(): void
    {
        $this->travelTo('2026-08-01 10:00:00');

        $first = $this->get(route('marathon.show'));
        $variant = $this->decryptedVariant($first);

        $this->withCookie(MarathonLandingCopySplit::COOKIE_NAME, $variant)
            ->get(route('marathon.show'))
            ->assertOk();

        $this->assertSame(1, MarathonLandingCopyVariantEvent::where('event_type', 'impression')->count());
    }

    public function test_visitor_keeps_the_same_variant_across_requests(): void
    {
        $this->travelTo('2026-08-01 10:00:00');

        $first = $this->get(route('marathon.show'));
        $variant = $this->decryptedVariant($first);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withCookie(MarathonLandingCopySplit::COOKIE_NAME, $variant)
                ->get(route('marathon.show'));
            $response->assertOk();
            $response->assertViewHas('copy', fn ($copy) => $copy['variant_key'] === $variant);
        }

        // No re-assignment across the 5 repeat requests: still exactly the
        // one impression from the very first (cookie-less) visit above.
        // (hasVariantCookie() is not a usable signal here -- Laravel's
        // in-process test CookieJar keeps re-attaching request 1's queued
        // cookie to every later response in the same test method, whether
        // or not resolveForRequest() actually re-queued it.)
        $this->assertSame(1, MarathonLandingCopyVariantEvent::where('event_type', 'impression')->count());
    }

    public function test_split_is_roughly_fifty_fifty_over_many_visitors(): void
    {
        $this->travelTo('2026-08-01 10:00:00');

        $counts = ['a' => 0, 'b' => 0];
        for ($i = 0; $i < 200; $i++) {
            $response = $this->get(route('marathon.show'));
            $counts[$this->decryptedVariant($response)]++;
        }

        // Both arms must actually appear; a 200-flip fair-coin split landing
        // entirely on one side is astronomically unlikely (this only checks
        // for a broken assignment path, not statistical significance).
        $this->assertGreaterThan(0, $counts['a']);
        $this->assertGreaterThan(0, $counts['b']);
        $this->assertSame(200, $counts['a'] + $counts['b']);
    }

    public function test_registration_tags_the_lead_with_its_visitor_variant_and_records_a_lead_event(): void
    {
        $this->travelTo('2026-08-01 10:00:00');

        $shown = $this->get(route('marathon.show'));
        $variant = $this->decryptedVariant($shown);

        $this->withCookie(MarathonLandingCopySplit::COOKIE_NAME, $variant)
            ->post(route('marathon.register'), [
                'name' => 'Виктор',
                'contact' => 'variant-lead@example.com',
                'track' => 'free',
                'quiz_goal' => 'grammar',
            ]);

        $lead = Lead::where('contact', 'variant-lead@example.com')->firstOrFail();
        $this->assertSame($variant, $lead->landing_copy_variant);
        $this->assertSame(1, MarathonLandingCopyVariantEvent::where('event_type', 'lead')
            ->where('variant', $variant)->count());
    }

    public function test_registration_without_a_variant_cookie_leaves_lead_variant_null_and_records_no_event(): void
    {
        $this->travelTo('2026-08-01 10:00:00');

        $this->post(route('marathon.register'), [
            'name' => 'Гость',
            'contact' => 'no-cookie-lead@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ]);

        $lead = Lead::where('contact', 'no-cookie-lead@example.com')->firstOrFail();
        $this->assertNull($lead->landing_copy_variant);
        $this->assertSame(0, MarathonLandingCopyVariantEvent::where('event_type', 'lead')->count());
    }

    public function test_after_cutoff_no_new_random_assignment_and_default_variant_served(): void
    {
        config(['marathon_landing_copy.variant' => 'a']);
        $this->travelTo('2026-11-01 00:00:01');

        $this->assertFalse(MarathonLandingCopySplit::isExperimentActive());

        $response = $this->get(route('marathon.show'));

        $response->assertOk();
        $response->assertViewHas('copy', fn ($copy) => $copy['variant_key'] === 'a');
        $this->assertFalse($this->hasVariantCookie($response));
        $this->assertSame(0, MarathonLandingCopyVariantEvent::where('event_type', 'impression')->count());
    }

    public function test_a_pre_cutoff_cookie_still_read_after_cutoff_for_lead_tagging(): void
    {
        $this->travelTo('2026-08-01 10:00:00');
        $shown = $this->get(route('marathon.show'));
        $variant = $this->decryptedVariant($shown);

        $this->travelTo('2026-11-02 09:00:00');

        $this->withCookie(MarathonLandingCopySplit::COOKIE_NAME, $variant)
            ->post(route('marathon.register'), [
                'name' => 'Поздний',
                'contact' => 'late-lead@example.com',
                'track' => 'free',
                'quiz_goal' => 'grammar',
            ]);

        $lead = Lead::where('contact', 'late-lead@example.com')->firstOrFail();
        $this->assertSame($variant, $lead->landing_copy_variant);
    }
}
