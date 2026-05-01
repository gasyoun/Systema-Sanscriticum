<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function scope_free_returns_only_free_lessons(): void
    {
        $course = Course::factory()->create();

        $free = Lesson::factory()->for($course)->free()->create(['title' => 'Открытый']);
        $paid = Lesson::factory()->for($course)->create(['title' => 'Платный']);

        $titles = Lesson::free()->pluck('title')->all();

        $this->assertContains('Открытый', $titles);
        $this->assertNotContains('Платный', $titles);
    }

    /** @test */
    public function is_free_is_cast_to_boolean(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->free()->create();

        $this->assertIsBool($lesson->is_free);
        $this->assertTrue($lesson->is_free);
    }
}
