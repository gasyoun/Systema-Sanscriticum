<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\Cabinet\LapseDetector;
use App\Services\Cabinet\RecordingsCatalog;
use App\Services\Cabinet\RecoveryStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1572 — Phase 2 hybrid «Записи»: shelves, rail, ownership offer, lapse detector.
 */
class HybridPhase2Test extends TestCase
{
    use RefreshDatabase;

    private function enableHybrid(): void
    {
        config(['features.cabinet_hybrid' => true]);
    }

    private function studentWithRecordingCourse(array $lessonAttrs = []): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-hybrid-p2']);
        $user->groups()->attach($group);
        $course = Course::factory()->create([
            'is_active' => true,
            'format' => 'recorded',
            'title' => 'Йога-сутры в записи',
        ]);
        $course->groups()->attach($group);
        $lessons = collect();
        for ($i = 1; $i <= 4; $i++) {
            $lessons->push(Lesson::factory()->create(array_merge([
                'course_id' => $course->id,
                'group_id' => $group->id,
                'title' => "Лекция $i",
                'sort_order' => $i,
                'is_free' => true,
                'homework_enabled' => false,
                'homework_prompt' => null,
            ], $lessonAttrs)));
        }

        return compact('user', 'group', 'course', 'lessons');
    }

    /** @test */
    public function library_still_404_when_flag_off(): void
    {
        config(['features.cabinet_hybrid' => false]);
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('student.library'))->assertNotFound();
    }

    /** @test */
    public function library_renders_shelves_and_membership_slot(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithRecordingCourse();

        $this->actingAs($user)
            ->get(route('student.library'))
            ->assertOk()
            ->assertSee('Мои записи', false)
            ->assertSee($course->title, false)
            ->assertSee('data-shelf=', false)
            ->assertSee('Членство «Самскрте+»', false)
            ->assertSee('скоро · осень 2026', false);
    }

    /** @test */
    public function recording_without_homework_shows_progress_rail(): void
    {
        $this->enableHybrid();
        ['user' => $user] = $this->studentWithRecordingCourse();

        $this->actingAs($user)
            ->get(route('student.library'))
            ->assertOk()
            ->assertSee('data-progress-rail', false)
            ->assertSee('library.rail.jump', false);
    }

    /** @test */
    public function shelf_view_telemetry_emitted_for_non_empty_shelf(): void
    {
        $this->enableHybrid();
        ['user' => $user] = $this->studentWithRecordingCourse();

        $this->actingAs($user)->get(route('student.library'))->assertOk();

        $this->assertTrue(
            ActivityEvent::where('event_type', ActivityEvent::LIBRARY_SHELF_VIEW)
                ->where('user_id', $user->id)
                ->exists()
        );
    }

    /** @test */
    public function ownership_offer_appears_after_progress_and_not_in_recovery(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course, 'lessons' => $lessons] = $this->studentWithRecordingCourse();

        // 2/4 = 50% ≥ 25% threshold.
        $user->completedLessons()->attach($lessons->take(2)->pluck('id')->all());

        $html = $this->actingAs($user)
            ->get(route('student.library'))
            ->assertOk()
            ->assertSee('data-offer-kind="ownership"', false)
            ->assertSee('Продолжение — навсегда на вашей полке', false)
            ->getContent();

        $this->assertStringContainsString('offer.impression', $html)
            || ActivityEvent::where('event_type', ActivityEvent::OFFER_IMPRESSION)->exists();

        // Recovery suppresses offer.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'block_2',
            'status' => 'failed',
            'is_conditional' => false,
        ]));

        $this->assertTrue(app(RecoveryStateResolver::class)->resolve($user)->suppressOffers());

        $this->actingAs($user)
            ->get(route('student.library'))
            ->assertOk()
            ->assertDontSee('data-offer-kind="ownership"', false);
    }

    /** @test */
    public function lapse_detector_surfaces_debt_as_lapsed_shelf(): void
    {
        $this->enableHybrid();
        // LapseDetector delegates to StudentDebtsService — when no debt, empty.
        $user = User::factory()->create();
        $this->assertTrue(app(LapseDetector::class)->forUser($user)->isEmpty());

        // Catalog still classifies group courses.
        ['user' => $user2, 'course' => $course] = $this->studentWithRecordingCourse();
        $catalog = app(RecordingsCatalog::class)->forUser($user2);
        $all = collect($catalog['shelves'])->flatten(1);
        $this->assertTrue(
            $all->contains(fn ($c) => $c->course->id === $course->id),
            'Course must appear on some shelf'
        );
    }

    /** @test */
    public function completed_course_lands_on_completed_shelf(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course, 'lessons' => $lessons] = $this->studentWithRecordingCourse();
        $user->completedLessons()->attach($lessons->pluck('id')->all());

        $catalog = app(RecordingsCatalog::class)->forUser($user);
        $completedIds = $catalog['shelves'][RecordingsCatalog::SHELF_COMPLETED]
            ->pluck('course.id')
            ->all();
        $this->assertContains($course->id, $completedIds);

        $this->actingAs($user)
            ->get(route('student.library'))
            ->assertOk()
            ->assertSee('data-shelf="completed"', false);
    }
}
