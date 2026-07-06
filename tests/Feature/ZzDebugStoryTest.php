<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZzDebugStoryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function debug_story(): void
    {
        $landing = LandingPage::create([
            'title' => 'X',
            'slug' => 'zz-debug-story',
            'is_active' => true,
            'content' => [[
                'type' => 'student_story_block',
                'data' => ['title' => 'T', 'stories' => []],
            ]],
        ]);

        $this->withoutExceptionHandling();
        $this->get('/'.$landing->slug)->assertOk();
    }
}
