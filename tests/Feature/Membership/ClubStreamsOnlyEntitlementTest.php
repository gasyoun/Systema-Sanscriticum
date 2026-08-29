<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Enums\RecordingKind;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\ClubStreamTariffCatalog;
use App\Services\Membership\RecordingAccessPolicy;

/**
 * H3648 — 26-08 ruling: Club grants club-stream / club-efir recordings only.
 * Course-lesson recordings stay on the course-purchase path.
 */
final class ClubStreamsOnlyEntitlementTest extends MembershipTestCase
{
    private Course $paidCourse;

    private Lesson $courseLesson;

    private Lesson $clubStream;

    private Lesson $clubEfir;

    private User $buyer;

    private User $clubOnly;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.membership_tiered', true);
        config()->set('features.membership_club_streams_only', true);
        config()->set('features.membership_recording_shadow', false);
        config()->set('features.membership_recording_pilot', false);
        config()->set('features.membership_recording_enforce', true);

        $this->paidCourse = Course::factory()->create();
        $group = Group::create(['name' => 'Поток курса '.$this->paidCourse->id]);
        $this->paidCourse->groups()->attach($group->id);
        $this->courseLesson = Lesson::factory()->create([
            'course_id' => $this->paidCourse->id,
            'group_id' => $group->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/course-lesson',
            'recording_kind' => RecordingKind::CourseLesson->value,
        ]);
        $this->clubStream = Lesson::factory()->create([
            'course_id' => $this->clubCourse->id,
            'group_id' => $this->clubGroup->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/club-stream',
            'recording_kind' => RecordingKind::ClubStream->value,
        ]);
        $this->clubEfir = Lesson::factory()->create([
            'course_id' => $this->clubCourse->id,
            'group_id' => $this->clubGroup->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/club-efir',
            'recording_kind' => RecordingKind::ClubEfir->value,
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

        $this->clubOnly = User::factory()->create();
        $this->payClub($this->clubOnly, 1, MembershipTier::Club);
    }

    public function test_course_buyer_without_club_sees_course_lesson_recording(): void
    {
        $decision = app(RecordingAccessPolicy::class)->decide(
            $this->buyer->fresh(),
            $this->paidCourse,
            $this->courseLesson,
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('course_purchase', $decision->reason);
    }

    public function test_club_member_without_course_purchase_does_not_see_course_lesson_recording(): void
    {
        $decision = app(RecordingAccessPolicy::class)->decide(
            $this->clubOnly->fresh(),
            $this->paidCourse,
            $this->courseLesson,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('course_not_purchased', $decision->reason);
    }

    public function test_club_member_sees_club_stream_and_efir_recordings(): void
    {
        $stream = app(RecordingAccessPolicy::class)->decide(
            $this->clubOnly->fresh(),
            $this->clubCourse,
            $this->clubStream,
        );
        $efir = app(RecordingAccessPolicy::class)->decide(
            $this->clubOnly->fresh(),
            $this->clubCourse,
            $this->clubEfir,
        );

        $this->assertTrue($stream->allowed);
        $this->assertSame('club_stream', $stream->reason);
        $this->assertTrue($efir->allowed);
        $this->assertSame('club_stream', $efir->reason);
    }

    public function test_course_buyer_without_club_does_not_see_club_stream_recording(): void
    {
        $decision = app(RecordingAccessPolicy::class)->decide(
            $this->buyer->fresh(),
            $this->clubCourse,
            $this->clubStream,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('club_stream_requires_club', $decision->reason);
    }

    public function test_flag_off_keeps_h2744_club_plus_purchase_predicate(): void
    {
        config()->set('features.membership_club_streams_only', false);
        app()->forgetInstance(RecordingAccessPolicy::class);

        $withoutClub = app(RecordingAccessPolicy::class)->decide(
            $this->buyer->fresh(),
            $this->paidCourse,
            $this->courseLesson,
        );
        $this->assertFalse($withoutClub->allowed);

        $this->payClub($this->buyer, 1, MembershipTier::Club);
        app()->forgetInstance(RecordingAccessPolicy::class);
        $withClub = app(RecordingAccessPolicy::class)->decide(
            $this->buyer->fresh(),
            $this->paidCourse,
            $this->courseLesson,
        );
        $this->assertTrue($withClub->allowed);
        $this->assertSame('club_or_top', $withClub->reason);
    }

    public function test_club_course_has_three_d20_tariffs_inactive_while_flag_off(): void
    {
        config()->set('features.membership_club_streams_only', false);
        $catalog = app(ClubStreamTariffCatalog::class);
        $rows = $catalog->ensure($this->clubCourse, persist: true);

        $this->assertCount(3, $rows);
        $this->assertSame([1, 3, 12], $rows->pluck('membership_months')->sort()->values()->all());
        $this->assertSame([2000.0, 5700.0, 20400.0], $rows->sortBy('membership_months')->pluck('price')->map(fn ($p) => (float) $p)->values()->all());
        $this->assertTrue($rows->every(fn ($tariff) => $tariff->is_active === false));
        $this->assertFalse($catalog->enabled());
    }

    public function test_flag_on_activates_the_three_d20_club_tariffs_without_tochka(): void
    {
        config()->set('features.membership_club_streams_only', false);
        app(ClubStreamTariffCatalog::class)->ensure($this->clubCourse, persist: true);

        config()->set('features.membership_club_streams_only', true);
        $rows = app(ClubStreamTariffCatalog::class)->ensure($this->clubCourse, persist: true);

        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($tariff) => $tariff->is_active === true));
        $this->assertTrue($rows->every(fn ($tariff) => $tariff->hasExpectedMembershipPrice()));
    }

    public function test_ensure_command_dry_run_does_not_write(): void
    {
        $before = app(ClubStreamTariffCatalog::class)->existingOn($this->clubCourse)->count();

        $this->artisan('membership:ensure-club-stream-tariffs')
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        $this->assertSame($before, app(ClubStreamTariffCatalog::class)->existingOn($this->clubCourse)->count());
    }

    public function test_rehearsal_accepts_streams_only_matrix_without_live_charge(): void
    {
        config()->set('features.membership_club_streams_only', true);
        app(ClubStreamTariffCatalog::class)->ensure($this->clubCourse, persist: true);
        foreach ([MembershipTier::Basic, MembershipTier::Club] as $tier) {
            foreach ([1, 3, 12] as $months) {
                $this->clubTariff($months, $tier);
            }
        }

        $this->artisan('membership:rehearse')
            ->expectsOutputToContain('club streams-only')
            ->expectsOutputToContain('tiers + 1/3/12')
            ->assertSuccessful();
    }
}
