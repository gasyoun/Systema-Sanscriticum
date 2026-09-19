<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class MarathonAcquisitionAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $route = 'marathon.register'): void
    {
        $this->post(route($route), [
            'name' => 'Анна', 'contact' => 'first@example.com',
            'track' => 'free', 'quiz_goal' => 'grammar',
        ])->assertRedirect();
    }

    public function test_landing_campaign_survives_clean_navigation_and_duplicate_registration(): void
    {
        LandingPage::create(['title' => 'Intro', 'slug' => config('marathon.landing_slug'), 'is_active' => true]);
        $this->withHeader('referer', 'https://youtube.com/watch?v=private')->get(route('marathon.show').'?utm_source=youtube&utm_medium=video&utm_content=lesson1')->assertOk();
        $this->withHeader('referer', route('marathon.show'))->get(route('marathon.show'))->assertOk();
        $this->register();
        $lead = Lead::sole();
        $this->assertSame('youtube', $lead->utm_source);
        $this->assertSame('lesson1', $lead->utm_content);
        $this->assertSame('https://youtube.com/watch', $lead->referrer);
        $this->withSession([config('tracked_links.session_key') => ['utm_source' => 'different']]);
        RateLimiter::clear('marathon-register:127.0.0.1');
        $this->register();
        $this->assertSame('youtube', Lead::sole()->utm_source);
    }

    public function test_malformed_fields_are_ignored_and_strings_are_bounded(): void
    {
        $this->get(route('marathon.show').'?'.http_build_query([
            'ref' => ['bad'], 'utm_source' => ['bad'], 'utm_campaign' => str_repeat('я', 300),
        ]))->assertOk();
        $this->register();
        $lead = Lead::sole();
        $this->assertNull($lead->utm_source);
        $this->assertSame(255, mb_strlen($lead->utm_campaign));
        $this->assertNull($lead->referrer);
    }

    public function test_internal_referrer_does_not_turn_unknown_into_an_acquisition_source(): void
    {
        $this->withHeader('referer', route('marathon.show'))->get(route('marathon.show'))->assertOk();
        $this->register();
        $this->assertNull(Lead::sole()->utm_source);
        $this->assertNull(Lead::sole()->referrer);
    }

    public function test_existing_tracked_link_session_is_preserved_for_january_cohort(): void
    {
        $this->withSession([config('tracked_links.session_key') => ['utm_source' => 'tracked', 'utm_campaign' => 'first']]);
        $this->get(route('marathon.show').'?utm_source=later')->assertOk();
        $this->register('marathon.january.register');
        $this->assertSame('tracked', Lead::sole()->utm_source);
        $this->assertSame('first', Lead::sole()->utm_campaign);
    }
}
