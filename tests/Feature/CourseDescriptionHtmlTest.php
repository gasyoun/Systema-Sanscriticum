<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * H2896 residual #8 — public shop page must not echo teacher HTML unsanitized.
 * Quiet DB writes simulate rows saved before the mutator existed.
 */
class CourseDescriptionHtmlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_page_does_not_echo_script_from_legacy_description(): void
    {
        $course = Course::factory()->create(['is_visible' => true, 'description' => '<p>safe</p>']);
        Course::whereKey($course->id)->update([
            'description' => '<p>О курсе</p><script>alert(1)</script><img src=x onerror=alert(1)>',
        ]);

        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertSee('О курсе', false)
            ->assertDontSee('<script', false)
            ->assertDontSee('onerror', false)
            ->assertDontSee('alert(1)', false);
    }

    #[Test]
    public function mutator_sanitizes_on_eloquent_save(): void
    {
        $course = Course::factory()->create([
            'description' => '<p>ok</p><script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script', (string) $course->description);
        $this->assertStringContainsString('ok', (string) $course->description);
    }
}
