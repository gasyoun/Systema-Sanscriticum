<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Support\KinescopePilot;
use App\Support\VideoEmbed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1451 — Wave 3 Kinescope pilot (Anton ops-gaps).
 * Embed only when flag ON + course id matches + URL is Kinescope.
 */
class KinescopePilotTest extends TestCase
{
    use RefreshDatabase;

    private const KINESCOPE_URL = 'https://kinescope.io/embed/pilotVideo99';

    /** @test */
    public function embed_null_when_flag_off(): void
    {
        config([
            'features.kinescope_pilot' => false,
            'video.kinescope_pilot_course_id' => 1,
        ]);

        $this->assertNull(KinescopePilot::embedForCourse(self::KINESCOPE_URL, 1));
        $this->assertFalse(KinescopePilot::isActiveForCourse(1));
    }

    /** @test */
    public function embed_null_when_course_not_pilot(): void
    {
        config([
            'features.kinescope_pilot' => true,
            'video.kinescope_pilot_course_id' => 42,
        ]);

        $this->assertNull(KinescopePilot::embedForCourse(self::KINESCOPE_URL, 7));
        $this->assertFalse(KinescopePilot::isActiveForCourse(7));
        $this->assertTrue(KinescopePilot::isActiveForCourse(42));
    }

    /** @test */
    public function embed_null_when_url_not_kinescope(): void
    {
        config([
            'features.kinescope_pilot' => true,
            'video.kinescope_pilot_course_id' => 5,
        ]);

        $this->assertNull(KinescopePilot::embedForCourse('https://www.youtube.com/watch?v=abc12345678', 5));
    }

    /** @test */
    public function embed_returns_kinescope_iframe_for_pilot_course(): void
    {
        config([
            'features.kinescope_pilot' => true,
            'video.kinescope_pilot_course_id' => 5,
        ]);

        $embed = KinescopePilot::embedForCourse(self::KINESCOPE_URL, 5);
        $this->assertSame(VideoEmbed::embed(self::KINESCOPE_URL), $embed);
        $this->assertStringContainsString('kinescope.io/embed/', $embed);
    }

    /** @test */
    public function lesson_page_renders_kinescope_iframe_only_for_pilot_course(): void
    {
        config([
            'features.kinescope_pilot' => true,
        ]);

        $pilot = Course::factory()->create();
        $other = Course::factory()->create();
        config(['video.kinescope_pilot_course_id' => $pilot->id]);

        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $pilot->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $other->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $pilotLesson = Lesson::factory()->for($pilot)->free()->create([
            'video_url' => self::KINESCOPE_URL,
            'block_number' => 1,
        ]);
        $otherLesson = Lesson::factory()->for($other)->free()->create([
            'video_url' => self::KINESCOPE_URL,
            'block_number' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $pilot->slug, 'lessonId' => $pilotLesson->id]))
            ->assertOk()
            ->assertSee('id="kinescope-player"', false)
            ->assertSee('kinescope.io/embed/pilotVideo99', false)
            ->assertSee('player.kinescope.io/latest/iframe.player.js', false);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $other->slug, 'lessonId' => $otherLesson->id]))
            ->assertOk()
            ->assertDontSee('id="kinescope-player"', false)
            ->assertDontSee('player.kinescope.io/latest/iframe.player.js', false);
    }

    /** @test */
    public function lesson_page_has_no_kinescope_when_flag_off(): void
    {
        config([
            'features.kinescope_pilot' => false,
        ]);

        $course = Course::factory()->create();
        config(['video.kinescope_pilot_course_id' => $course->id]);

        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
        $lesson = Lesson::factory()->for($course)->free()->create([
            'video_url' => self::KINESCOPE_URL,
            'block_number' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertOk()
            ->assertDontSee('id="kinescope-player"', false);
    }
}
