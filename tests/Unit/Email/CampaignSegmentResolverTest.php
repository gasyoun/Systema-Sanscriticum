<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Models\Course;
use App\Models\Lead;
use App\Models\User;
use App\Services\Email\CampaignSegmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1449 B3 — fail-safe segment resolution: an unknown filter must resolve to
 * EMPTY, never to "all users".
 */
class CampaignSegmentResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_subscribers_only_includes_consenting_users_with_email(): void
    {
        User::factory()->create(['wants_email_announcements' => true, 'email' => 'yes@example.com']);
        User::factory()->create(['wants_email_announcements' => false, 'email' => 'no@example.com']);

        $result = (new CampaignSegmentResolver)->resolve(['type' => 'all_subscribers']);

        $this->assertCount(1, $result);
        $this->assertSame('yes@example.com', $result->first()->email);
    }

    public function test_course_segment_only_includes_that_courses_students(): void
    {
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();

        $student = User::factory()->create(['wants_email_announcements' => true]);
        $course->users()->attach($student->id);

        $outsider = User::factory()->create(['wants_email_announcements' => true]);
        $otherCourse->users()->attach($outsider->id);

        $result = (new CampaignSegmentResolver)->resolve(['type' => 'course', 'course_id' => $course->id]);

        $this->assertCount(1, $result);
        $this->assertSame($student->id, $result->first()->id);
    }

    public function test_lead_stage_segment_resolves_to_matching_users_by_email(): void
    {
        // 'qualified' is seeded by the lead_stages migration — no factory needed.
        Lead::factory()->create(['status' => 'qualified', 'email' => 'lead@example.com']);
        User::factory()->create(['email' => 'lead@example.com']);
        User::factory()->create(['email' => 'unrelated@example.com']);

        $result = (new CampaignSegmentResolver)->resolve(['type' => 'lead_stage', 'stage' => 'qualified']);

        $this->assertCount(1, $result);
        $this->assertSame('lead@example.com', $result->first()->email);
    }

    public function test_unknown_filter_resolves_to_empty_never_all_users(): void
    {
        User::factory()->count(3)->create(['wants_email_announcements' => true]);

        $result = (new CampaignSegmentResolver)->resolve(['type' => 'something_undefined']);

        $this->assertCount(0, $result);
    }

    public function test_null_segment_resolves_to_empty(): void
    {
        User::factory()->count(3)->create(['wants_email_announcements' => true]);

        $result = (new CampaignSegmentResolver)->resolve(null);

        $this->assertCount(0, $result);
    }

    public function test_course_segment_with_missing_course_id_resolves_to_empty(): void
    {
        User::factory()->count(2)->create(['wants_email_announcements' => true]);

        $result = (new CampaignSegmentResolver)->resolve(['type' => 'course']);

        $this->assertCount(0, $result);
    }
}
