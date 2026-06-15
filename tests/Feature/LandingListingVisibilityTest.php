<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LandingListingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function landing(string $slug, bool $listed): LandingPage
    {
        return LandingPage::create([
            'title' => 'Title '.$slug,
            'slug' => $slug,
            'is_active' => true,
            'is_listed' => $listed,
            'content' => [
                ['type' => 'video_block', 'data' => ['video_url' => 'https://example.com/embed/'.$slug]],
            ],
        ]);
    }

    /** @test */
    public function listed_landing_appears_in_homepage_catalog_but_unlisted_does_not(): void
    {
        $this->landing('listed-page', true);
        $this->landing('hidden-record-page', false);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('listed-page');
        $response->assertDontSee('hidden-record-page');
    }

    /** @test */
    public function unlisted_landing_is_still_reachable_by_direct_url(): void
    {
        $this->landing('hidden-record-page', false);

        $this->get('/hidden-record-page')->assertOk();
    }

    /** @test */
    public function sitemap_excludes_unlisted_landing(): void
    {
        $this->landing('listed-page', true);
        $this->landing('hidden-record-page', false);
        Cache::forget('sitemap.xml');

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertSee('listed-page');
        $response->assertDontSee('hidden-record-page');
    }
}
