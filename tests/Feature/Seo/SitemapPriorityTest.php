<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SitemapPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_priorities_donate_recorded_live(): void
    {
        Course::factory()->create([
            'slug' => 'donat-na-razvitie-ors',
            'format' => 'recorded',
            'is_visible' => true,
        ]);
        Course::factory()->create([
            'slug' => 'some-recorded-course',
            'format' => 'recorded',
            'is_visible' => true,
        ]);
        Course::factory()->live()->create([
            'slug' => 'some-live-course',
            'is_visible' => true,
        ]);

        Cache::forget('sitemap.xml');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertPriority($xml, 'donat-na-razvitie-ors', '0.3');
        $this->assertPriority($xml, 'some-recorded-course', '0.6');
        $this->assertPriority($xml, 'some-live-course', '0.8');
    }

    private function assertPriority(string $xml, string $slug, string $priority): void
    {
        $pattern = '#<loc>[^<]*/k/'.preg_quote($slug, '#').'</loc>.*?<priority>'.preg_quote($priority, '#').'</priority>#s';
        $this->assertMatchesRegularExpression(
            $pattern,
            $xml,
            "Expected /k/{$slug} to have priority {$priority}"
        );
    }
}
