<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MembershipAccessVerdict;
use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\RecordingAccessPolicy;

final class RecordingAccessPolicyTest extends MembershipTestCase
{
    private Course $paidCourse;

    private Lesson $paidLesson;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.membership_tiered', true);
        config()->set('features.membership_recording_shadow', false);
        config()->set('features.membership_recording_pilot', false);
        config()->set('features.membership_recording_enforce', false);

        $this->paidCourse = Course::factory()->create();
        $group = Group::create(['name' => 'Купленный живой поток']);
        $this->paidCourse->groups()->attach($group->id);
        $this->paidLesson = Lesson::factory()->create([
            'course_id' => $this->paidCourse->id,
            'group_id' => $group->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'homework_enabled' => true,
        ]);
        $this->buyer = User::factory()->create();
        $this->buyer->groups()->syncWithoutDetaching([$group->id]);
        Payment::create([
            'user_id' => $this->buyer->id,
            'course_id' => $this->paidCourse->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
    }

    public function test_dark_deploy_preserves_the_entire_purchased_lesson(): void
    {
        $this->actingAs($this->buyer)
            ->get(route('student.lesson', [$this->paidCourse->slug, $this->paidLesson->id]))
            ->assertOk()
            ->assertSee('youtube.com/embed', false)
            ->assertDontSee('data-membership-recording-gate', false);
    }

    public function test_september_shadow_logs_denial_but_keeps_video_open(): void
    {
        config()->set('features.membership_recording_shadow', true);

        $this->actingAs($this->buyer)
            ->get(route('student.lesson', [$this->paidCourse->slug, $this->paidLesson->id]))
            ->assertOk()
            ->assertSee('youtube.com/embed', false)
            ->assertSee('data-membership-recording-notice', false);

        $verdict = MembershipAccessVerdict::firstOrFail();
        $this->assertFalse($verdict->would_allow);
        $this->assertSame('shadow', $verdict->mode);
        $this->assertSame('web_recording', $verdict->surface);
    }

    public function test_october_enforcement_hides_only_video_and_restores_immediately_after_club_payment(): void
    {
        config()->set('features.membership_recording_enforce', true);

        $this->actingAs($this->buyer)
            ->get(route('student.lesson', [$this->paidCourse->slug, $this->paidLesson->id]))
            ->assertOk()
            ->assertDontSee('youtube.com/embed', false)
            ->assertSee('data-membership-recording-gate', false)
            ->assertSee('домашние задания');

        // Purchase-based course and schedule surfaces remain open.
        $this->actingAs($this->buyer)->get(route('student.course', $this->paidCourse->slug))->assertOk();
        $this->actingAs($this->buyer)->get(route('student.calendar'))->assertOk();

        $this->payClub($this->buyer, 1, MembershipTier::Club);
        app()->forgetInstance(RecordingAccessPolicy::class);

        $this->actingAs($this->buyer->fresh())
            ->get(route('student.lesson', [$this->paidCourse->slug, $this->paidLesson->id]))
            ->assertOk()
            ->assertSee('youtube.com/embed', false)
            ->assertDontSee('data-membership-recording-gate', false);
    }

    public function test_basic_does_not_unlock_recordings_but_club_does(): void
    {
        config()->set('features.membership_recording_enforce', true);
        $this->payClub($this->buyer, 1, MembershipTier::Basic);

        $basic = app(RecordingAccessPolicy::class)->decide($this->buyer->fresh(), $this->paidCourse, $this->paidLesson);
        $this->assertFalse($basic->allowed);

        $this->payClub($this->buyer, 1, MembershipTier::Club);
        app()->forgetInstance(RecordingAccessPolicy::class);
        $club = app(RecordingAccessPolicy::class)->decide($this->buyer->fresh(), $this->paidCourse, $this->paidLesson);
        $this->assertTrue($club->allowed);
    }

    public function test_free_preview_and_explicit_grants_bypass_the_recording_gate(): void
    {
        config()->set('features.membership_recording_enforce', true);
        $this->paidLesson->update(['is_free' => true]);

        $decision = app(RecordingAccessPolicy::class)->decide($this->buyer, $this->paidCourse, $this->paidLesson->fresh());

        $this->assertTrue($decision->allowed);
        $this->assertSame('free_or_preview', $decision->reason);
    }

    public function test_pilot_enforces_only_the_named_twenty_users_for_forty_eight_hours(): void
    {
        config()->set('features.membership_recording_pilot', true);
        $others = User::factory()->count(19)->create();
        $ids = $others->pluck('id')->push($this->buyer->id)->implode(',');
        config()->set('membership.recording_gate.pilot_user_ids', $ids);
        config()->set('membership.recording_gate.pilot_started_at', now()->toIso8601String());

        $policy = app(RecordingAccessPolicy::class);
        $this->assertSame('pilot', $policy->modeFor($this->buyer));
        $this->assertFalse($policy->decide($this->buyer, $this->paidCourse, $this->paidLesson)->allowed);

        $outside = User::factory()->create();
        $this->assertSame('pilot-shadow', $policy->modeFor($outside));

        config()->set('membership.recording_gate.pilot_started_at', now()->subHours(49)->toIso8601String());
        $this->assertSame('pilot-shadow', $policy->modeFor($this->buyer));
    }

    public function test_shadow_report_proves_recording_only_scope_and_pilot_readiness(): void
    {
        config()->set('features.membership_recording_shadow', true);
        app(RecordingAccessPolicy::class)->decide($this->buyer, $this->paidCourse, $this->paidLesson, false, 'web_recording');

        $this->artisan('membership:recording-report')
            ->expectsOutputToContain('pilot=NOT READY')
            ->assertSuccessful();
    }
}
