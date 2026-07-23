<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KinescopePilot;
use Tests\TestCase;

/**
 * H1451 — pilot scope: flag + course id gate.
 * Executor: Grok 4.5 (grok-4.5) via xAI.
 */
class KinescopePilotTest extends TestCase
{
    public function test_flag_off_returns_null_even_for_matching_course(): void
    {
        config([
            'features.kinescope_pilot' => false,
            'video.kinescope_pilot_course_id' => 42,
        ]);

        $this->assertFalse(KinescopePilot::isActiveForCourse(42));
        $this->assertNull(KinescopePilot::embedForCourse('https://kinescope.io/abcDEFgh12', 42));
    }

    public function test_flag_on_wrong_course_returns_null(): void
    {
        config([
            'features.kinescope_pilot' => true,
            'video.kinescope_pilot_course_id' => 42,
        ]);

        $this->assertFalse(KinescopePilot::isActiveForCourse(99));
        $this->assertNull(KinescopePilot::embedForCourse('https://kinescope.io/abcDEFgh12', 99));
    }

    public function test_flag_on_pilot_course_emits_kinescope_embed(): void
    {
        config([
            'features.kinescope_pilot' => true,
            'video.kinescope_pilot_course_id' => 42,
        ]);

        $this->assertTrue(KinescopePilot::isActiveForCourse(42));
        $this->assertSame(
            'https://kinescope.io/embed/abcDEFgh12',
            KinescopePilot::embedForCourse('https://kinescope.io/abcDEFgh12', 42)
        );
    }

    public function test_non_kinescope_url_stays_null_on_pilot_course(): void
    {
        config([
            'features.kinescope_pilot' => true,
            'video.kinescope_pilot_course_id' => 42,
        ]);

        $this->assertNull(KinescopePilot::embedForCourse('https://youtu.be/abc12345678', 42));
    }

    public function test_missing_pilot_course_id_is_inactive(): void
    {
        config([
            'features.kinescope_pilot' => true,
            'video.kinescope_pilot_course_id' => null,
        ]);

        $this->assertFalse(KinescopePilot::isActiveForCourse(1));
    }
}
