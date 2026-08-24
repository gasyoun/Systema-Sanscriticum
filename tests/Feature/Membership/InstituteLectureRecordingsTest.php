<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\RecordingAccessPolicy;

/**
 * Открытые лекции МГ (план института N5): лекционный контракт доступа записей.
 *
 * Решение MG 23-08 поздним вечером: живьём — бесплатно всем (политика записи
 * live-поверхности не трогает); запись — бесплатна при ЛЮБОМ платном членстве
 * (Basic ₽1 000 и Club ₽2 000) и покупаема неплатными через обычный тарифный
 * контур. Применяется ТОЛЬКО к курсам из institute.lecture_course_ids.
 */
final class InstituteLectureRecordingsTest extends MembershipTestCase
{
    private Course $lectureCourse;

    private Lesson $lectureLesson;

    private Course $regularCourse;

    private Lesson $regularLesson;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.membership_tiered', true);
        config()->set('features.membership_recording_shadow', false);
        config()->set('features.membership_recording_pilot', false);
        config()->set('features.membership_recording_enforce', true);

        $this->lectureCourse = Course::factory()->create(['title' => 'Открытые лекции МГ']);
        $lectureGroup = Group::create(['name' => 'Открытые лекции — записи']);
        $this->lectureCourse->groups()->attach($lectureGroup->id);
        $this->lectureLesson = Lesson::factory()->create([
            'course_id' => $this->lectureCourse->id,
            'group_id' => $lectureGroup->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/'.uniqid(),
        ]);
        config()->set('institute.lecture_course_ids', [$this->lectureCourse->id]);

        // Контрольная группа: обычный курс НЕ из лекционного списка — прежние правила.
        $this->regularCourse = Course::factory()->create();
        $regularGroup = Group::create(['name' => 'Обычный курс — поток '.$this->regularCourse->id]);
        $this->regularCourse->groups()->attach($regularGroup->id);
        $this->regularLesson = Lesson::factory()->create([
            'course_id' => $this->regularCourse->id,
            'group_id' => $regularGroup->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/'.uniqid(),
        ]);
    }

    private function purchaseRecording(User $user, Course $course, string $tariff = 'full'): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1500,
            'tariff' => $tariff,
            'status' => 'paid',
        ]);
    }

    public function test_basic_membership_opens_open_lecture_recordings_without_purchase(): void
    {
        $member = User::factory()->create();
        $this->payClub($member, 1, MembershipTier::Basic);

        $decision = app(RecordingAccessPolicy::class)->decide(
            $member->fresh(), $this->lectureCourse, $this->lectureLesson
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('open_lecture', $decision->reason);
    }

    public function test_club_membership_opens_open_lecture_recordings(): void
    {
        $member = User::factory()->create();
        $this->payClub($member, 1, MembershipTier::Club);

        $decision = app(RecordingAccessPolicy::class)->decide(
            $member->fresh(), $this->lectureCourse, $this->lectureLesson
        );

        $this->assertTrue($decision->allowed);
    }

    public function test_non_member_purchases_the_recording_without_any_membership(): void
    {
        $buyer = User::factory()->create();
        $this->purchaseRecording($buyer, $this->lectureCourse);

        $decision = app(RecordingAccessPolicy::class)->decide(
            $buyer, $this->lectureCourse, $this->lectureLesson
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('open_lecture', $decision->reason);
    }

    public function test_non_member_without_purchase_is_denied_under_enforcement(): void
    {
        $guest = User::factory()->create();

        $decision = app(RecordingAccessPolicy::class)->decide(
            $guest, $this->lectureCourse, $this->lectureLesson
        );

        $this->assertFalse($decision->wouldAllow);
        $this->assertFalse($decision->allowed);
        $this->assertSame('course_not_purchased', $decision->reason);
    }

    public function test_expired_membership_falls_back_to_purchase_rule(): void
    {
        $lapsed = User::factory()->create();
        $this->payClub($lapsed, 1, MembershipTier::Basic);
        // Гейт активности — grace_until (не ends_at): гасим и период, и грейс.
        $past = now()->subDays(5);
        ClubMembership::query()->where('user_id', $lapsed->id)
            ->update(['ends_at' => $past, 'grace_until' => $past]);

        // Истекшее членство само по себе запись не открывает…
        $denied = app(RecordingAccessPolicy::class)->decide(
            $lapsed->fresh(), $this->lectureCourse, $this->lectureLesson
        );
        $this->assertFalse($denied->wouldAllow);

        // …а купленная запись открывает и после истечения членства.
        $this->purchaseRecording($lapsed, $this->lectureCourse);
        app()->forgetInstance(RecordingAccessPolicy::class);

        $allowed = app(RecordingAccessPolicy::class)->decide(
            $lapsed->fresh(), $this->lectureCourse, $this->lectureLesson
        );
        $this->assertTrue($allowed->allowed);
    }

    public function test_regular_courses_keep_the_club_recording_minimum(): void
    {
        // Basic на обычном курсе — прежнее поведение: recordings = минимум club.
        $basic = User::factory()->create();
        $this->payClub($basic, 1, MembershipTier::Basic);

        $decision = app(RecordingAccessPolicy::class)->decide(
            $basic->fresh(), $this->regularCourse, $this->regularLesson
        );

        $this->assertFalse($decision->wouldAllow);
        $this->assertNotSame('open_lecture', $decision->reason);

        // Неплатный купил запись обычного курса — без club-минимума не пускает.
        $buyer = User::factory()->create();
        $this->purchaseRecording($buyer, $this->regularCourse);

        $purchased = app(RecordingAccessPolicy::class)->decide(
            $buyer, $this->regularCourse, $this->regularLesson
        );

        $this->assertFalse($purchased->wouldAllow);
        $this->assertSame('tier_below_club', $purchased->reason);
    }

    public function test_shadow_mode_logs_lecture_verdicts_but_keeps_video_open(): void
    {
        config()->set('features.membership_recording_enforce', false);
        config()->set('features.membership_recording_shadow', true);

        $guest = User::factory()->create();

        $decision = app(RecordingAccessPolicy::class)->decide(
            $guest, $this->lectureCourse, $this->lectureLesson
        );

        $this->assertTrue($decision->allowed, 'Shadow не закрывает видео.');
        $this->assertTrue($decision->shadow);
        $this->assertFalse($decision->wouldAllow);
    }

    public function test_off_mode_keeps_everything_open_as_today(): void
    {
        config()->set('features.membership_recording_enforce', false);

        $guest = User::factory()->create();

        $decision = app(RecordingAccessPolicy::class)->decide(
            $guest, $this->lectureCourse, $this->lectureLesson
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('off', $decision->mode);
    }
}
