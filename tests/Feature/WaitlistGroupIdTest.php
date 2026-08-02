<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1755 residual: not-yet-launched groups have a stable ID; waitlist can pin people
 * to that group before status goes active.
 */
class WaitlistGroupIdTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function waitlist_entry_can_attach_to_forming_group(): void
    {
        $group = Group::factory()->create([
            'name' => 'Группа А-сентябрь',
            'slug' => 'gruppa-a-sentyabr',
            'status' => 'forming',
        ]);

        $entry = WaitlistEntry::create([
            'name' => 'Тест Ученик',
            'group_id' => $group->id,
            'status' => 'waiting',
            'level' => 'novice',
        ]);

        $this->assertSame($group->id, $entry->fresh()->group_id);
        $this->assertTrue($group->waitlistEntries()->whereKey($entry->id)->exists());
        $this->assertSame('gruppa-a-sentyabr', $group->publicCode());
    }

    /** @test */
    public function course_never_repeat_defaults_false_and_is_cast(): void
    {
        $course = Course::factory()->create();
        $this->assertFalse((bool) $course->fresh()->never_repeat);

        $course->update(['never_repeat' => true]);
        $this->assertTrue((bool) $course->fresh()->never_repeat);
    }
}
