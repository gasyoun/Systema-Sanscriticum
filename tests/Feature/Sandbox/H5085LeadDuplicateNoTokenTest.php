<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Models\MarathonEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H5085 regression · lead-duplicate-flash-magnet-token-disclosure.
 *
 * Same victim setup as the NV-11 proof, assertions flipped to the fixed
 * invariant: knowing the dedupe key (email/contact) must never return the
 * existing lead's bearer magnet_token — no duplicate_deep_link, no
 * status_connect_links, no marathon_telegram_link on either duplicate path
 * (leads store + marathon register), while a genuinely NEW lead still
 * receives its own deep link.
 */
class H5085LeadDuplicateNoTokenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok123nv11';

    private const VICTIM_EMAIL = 'victim@example.test';

    private function configureTelegram(): void
    {
        MarketingSetting::create([
            'tg_bot_username' => 'nv11_bot',
            'tg_bot_token' => '111:token',
        ]);
        MarketingSetting::flushCached();
    }

    /** @test */
    public function duplicate_lead_flash_carries_no_victim_token(): void
    {
        $this->configureTelegram();

        $landing = LandingPage::create([
            'title' => 'H5085 лендинг',
            'slug' => 'h5085',
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_default_channel' => 'telegram',
        ]);

        $victim = Lead::create([
            'name' => 'Жертва',
            'contact' => self::VICTIM_EMAIL,
            'email' => self::VICTIM_EMAIL,
            'landing_page_id' => $landing->id,
            'magnet_channel' => 'telegram',
            'magnet_token' => self::TOKEN,
        ]);
        $this->assertNotNull($victim->magnet_token, 'victim carries the bearer token');

        // Anonymous attacker submits the SAME email (knowledge factor only).
        RateLimiter::clear('lead-submit:127.0.0.1');
        $response = $this->post('/leads/store', [
            'contact' => self::VICTIM_EMAIL,
            'email' => self::VICTIM_EMAIL,
            'landing_page_id' => $landing->id,
            'is_promo_agreed' => '1',
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertRedirect(route('thank.you'));

        // The duplicate flash must not carry any token-bearing link of the
        // existing lead.
        $this->assertNull(session('duplicate_deep_link'), 'duplicate flash must not carry a deep link');
        $this->assertNull(session('duplicate_channel'), 'duplicate flash must not carry a channel');
        $this->assertNull(session('status_connect_links'), 'duplicate flash must not carry status connect links');

        // And the rendered thankyou page never hands the token over.
        $page = $this->get(route('thank.you'));
        $page->assertOk();
        $this->assertStringNotContainsString(self::TOKEN, $page->getContent(), 'victim bearer token must not reach the anonymous submitter');
    }

    /** @test */
    public function marathon_duplicate_does_not_re_disclose_victim_telegram_link(): void
    {
        $this->configureTelegram();

        $landing = LandingPage::create([
            'title' => 'Марафон H5085',
            'slug' => (string) config('marathon.landing_slug'),
            'is_active' => true,
        ]);

        $victim = Lead::create([
            'name' => 'Жертва марафона',
            'contact' => self::VICTIM_EMAIL,
            'email' => self::VICTIM_EMAIL,
            'landing_page_id' => $landing->id,
            'magnet_channel' => 'telegram',
            'magnet_token' => self::TOKEN,
        ]);

        MarathonEnrollment::create([
            'lead_id' => $victim->id,
            'track' => MarathonEnrollment::TRACK_FREE,
            'cohort' => MarathonEnrollment::COHORT_ZERO,
            'quiz_goal' => 'grammar',
            'ab_arm' => MarathonEnrollment::computeArm($victim->id),
            'day0_started_at' => now(),
        ]);

        RateLimiter::clear('marathon-register:127.0.0.1');
        $response = $this->post('/online/konsultaciya', [
            'name' => 'Аноним Анонимов',
            'contact' => self::VICTIM_EMAIL,
            'email' => self::VICTIM_EMAIL,
            'track' => MarathonEnrollment::TRACK_FREE,
            'quiz_goal' => 'grammar',
            'is_promo_agreed' => '1',
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertRedirect(route('marathon.show'));

        // The bot IS configured, so deepLink() would have returned a token
        // URL before the fix — its absence from the session is decisive.
        $this->assertNull(session('marathon_telegram_link'), 'resumed enrollment must not flash the victim telegram deep link');
    }

    /** @test */
    public function control_new_lead_still_receives_its_own_deep_link(): void
    {
        $this->configureTelegram();

        $landing = LandingPage::create([
            'title' => 'H5085 новый лид',
            'slug' => 'h5085-new',
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/h5085.pdf',
            'lead_magnet_title' => 'Файл H5085',
            'lead_magnet_default_channel' => 'telegram',
        ]);

        RateLimiter::clear('lead-submit:127.0.0.1');
        $response = $this->post('/leads/store', [
            'contact' => 'fresh.h5085@example.test',
            'email' => 'fresh.h5085@example.test',
            'landing_page_id' => $landing->id,
            'is_promo_agreed' => '1',
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertRedirect(route('thank.you'));

        $lead = Lead::where('email', 'fresh.h5085@example.test')->first();
        $this->assertNotNull($lead, 'new lead stored');
        $this->assertNotNull($lead->magnet_token, 'new lead got its own binding token');

        $links = session('magnet_deep_links', []);
        $this->assertNotEmpty($links, 'new lead still receives its magnet deep link');
        $this->assertStringContainsString($lead->magnet_token, implode(' ', $links), 'own deep link carries the OWN token');
    }
}
