<?php

declare(strict_types=1);

namespace Tests\Feature\Subhashita;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2167 — Subhāṣita test-only Course+Tariff+CourseBlock scaffold: 4 blocks × 4 lessons
 * = 16 total (D6 of PLAN_NALOPAKHYANA_SUBHASHITA_COURSES_2026.md — "always a batch of
 * 4 lessons... 4 batches per 4 lessons"), proving the platform's STANDARD block-course
 * pattern (Tariff::accessKey() / Lesson::isUnlockedBy() / BlockAccessMaterializer) works
 * unmodified for a new course slug. Zero new access-key logic — this is HalfBlockPurchaseTest's
 * exact prior-art pattern applied to Subhāṣita.
 *
 * NOT created here: the real production Course row (title/price/teacher/Group/Schedule
 * are human ops in Filament — see handoff fence). Every model below is throwaway
 * per-test factory data under RefreshDatabase.
 */
class SubhashitaCourseBlockScaffoldTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCKS = 4;

    private const LESSONS_PER_BLOCK = 4;

    /**
     * Test-only course row. Slug matches config/cohort_courses.php's default
     * SUBHASHITA_COURSE_SLUG (H2164) only because that is the natural slug — this
     * row itself is factory throwaway data, never the production course.
     */
    private function course(): Course
    {
        return Course::factory()->live()->create(['slug' => 'subhashita']);
    }

    /**
     * Materializes the 4×4 block/lesson scaffold described in the handoff.
     *
     * @return array{0: array<int, CourseBlock>, 1: array<int, array<int, Lesson>>}
     */
    private function scaffold(Course $course): array
    {
        $blocks = [];
        $lessons = [];

        foreach (range(1, self::BLOCKS) as $n) {
            // D6: finite live course, no fabricated go-live/end dates — CourseBlock.ends_at
            // stays null here, exactly like every other un-scheduled block scaffold.
            $blocks[$n] = CourseBlock::create([
                'course_id' => $course->id,
                'number' => $n,
                'title' => "Блок {$n}",
            ]);

            $lessons[$n] = [];
            foreach (range(1, self::LESSONS_PER_BLOCK) as $i) {
                $lessons[$n][] = Lesson::factory()->create([
                    'course_id' => $course->id,
                    'block_number' => $n,
                    'title' => "Блок {$n}, урок {$i}",
                ]);
            }
        }

        return [$blocks, $lessons];
    }

    private function pay(User $user, Course $course, string $tariffKey, float $amount): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => $tariffKey,
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function scaffold_has_four_blocks_of_four_lessons_each(): void
    {
        $course = $this->course();
        [$blocks, $lessons] = $this->scaffold($course);

        $this->assertCount(4, $blocks);
        $this->assertSame(16, Lesson::where('course_id', $course->id)->count());

        foreach (range(1, 4) as $n) {
            $this->assertCount(4, $lessons[$n]);
        }
    }

    /** @test */
    public function block_and_full_tariffs_use_the_platforms_standard_access_keys(): void
    {
        // Fence check: no new access-key scheme — accessKey() is the unmodified
        // block_N / full pattern from Tariff::accessKey().
        $course = $this->course();

        $blockTariff = Tariff::factory()->block(2)->create(['course_id' => $course->id, 'price' => 4800]);
        $fullTariff = Tariff::factory()->create([
            'course_id' => $course->id,
            'title' => 'Весь курс Subhāṣita',
            'price' => 16000,
        ]);

        $this->assertSame('block_2', $blockTariff->accessKey());
        $this->assertSame('full', $fullTariff->accessKey());
    }

    /** @test */
    public function paying_for_block_2_unlocks_exactly_its_four_lessons(): void
    {
        $course = $this->course();
        [, $lessons] = $this->scaffold($course);

        $user = User::factory()->create();
        $this->pay($user, $course, 'block_2', 4800);

        foreach (range(1, 4) as $n) {
            foreach ($lessons[$n] as $lesson) {
                $response = $this->actingAs($user)
                    ->get(route('student.lesson', [$course->slug, $lesson->id]));

                if ($n === 2) {
                    $response->assertOk();
                } else {
                    $response->assertRedirect(route('student.course', $course->slug));
                }
            }
        }
    }

    /** @test */
    public function paying_full_unlocks_all_sixteen_lessons(): void
    {
        $course = $this->course();
        [, $lessons] = $this->scaffold($course);

        $user = User::factory()->create();
        $this->pay($user, $course, 'full', 16000);

        foreach ($lessons as $blockLessons) {
            foreach ($blockLessons as $lesson) {
                $this->actingAs($user)
                    ->get(route('student.lesson', [$course->slug, $lesson->id]))
                    ->assertOk();
            }
        }
    }

    /** @test */
    public function upgrading_one_paid_block_to_full_credits_it_with_zero_new_code(): void
    {
        // Same upgradeCreditForUser() path as HalfBlockPurchaseTest — proves loyalty /
        // upgrade-credit needs no Subhāṣita-specific logic, just the platform default.
        config(['features.full_course_block_credit' => true]);

        $course = $this->course();
        $this->scaffold($course);
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_2', 4800);

        $fullTariff = Tariff::factory()->create(['course_id' => $course->id, 'price' => 16000]);

        $this->assertSame(4800.0, $fullTariff->upgradeCreditForUser($user));
        $this->assertSame(11200.0, $fullTariff->calculateFinalPriceForUser($user));
    }
}
