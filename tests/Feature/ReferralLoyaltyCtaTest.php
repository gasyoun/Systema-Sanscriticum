<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H3693 — referral CTA at three student-owned loyalty moments behind
 * features.referral_loyalty_cta (default OFF). The H1294 cabinet include
 * stays visible regardless of this flag.
 */
class ReferralLoyaltyCtaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    public function test_flag_defaults_off_and_partner_program_stays_off(): void
    {
        $this->assertFalse((bool) config('features.referral_loyalty_cta'));
        $this->assertFalse((bool) config('partner.enabled'));
    }

    public function test_accepted_homework_hides_loyalty_cta_when_flag_off(): void
    {
        [$student, $course, $lesson] = $this->studentWithAcceptedHomework();

        $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('Работа принята')
            ->assertDontSee('data-testid="referral-loyalty-cta-homework"', false)
            ->assertDontSee('Порекомендовать школу');
    }

    public function test_accepted_homework_shows_existing_partial_when_flag_on(): void
    {
        config(['features.referral_loyalty_cta' => true, 'referral.credit_amount' => 500]);
        [$student, $course, $lesson] = $this->studentWithAcceptedHomework();

        $response = $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]));

        $response->assertOk();
        $response->assertSee('data-testid="referral-loyalty-cta-homework"', false);
        $response->assertSee('Порекомендовать школу');
        $response->assertSee('в знак благодарности');
        $this->assertH1294Voice($response->getContent());
    }

    public function test_submitted_homework_hides_loyalty_cta_even_when_flag_on(): void
    {
        config(['features.referral_loyalty_cta' => true]);
        [$student, $course, $lesson] = $this->studentWithHomework(HomeworkSubmission::STATUS_SUBMITTED);

        $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertDontSee('data-testid="referral-loyalty-cta-homework"', false)
            ->assertDontSee('Порекомендовать школу');
    }

    public function test_course_complete_hides_loyalty_cta_when_flag_off(): void
    {
        [$student] = $this->enrolledStudentWithCompletedCourse();

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Все уроки пройдены')
            ->assertDontSee('data-testid="referral-loyalty-cta-course-complete"', false)
            ->assertSee('Порекомендовать школу');
    }

    public function test_course_complete_shows_existing_partial_when_flag_on(): void
    {
        config(['features.referral_loyalty_cta' => true]);
        [$student] = $this->enrolledStudentWithCompletedCourse();

        $response = $this->actingAs($student)
            ->get(route('student.dashboard'));

        $response->assertOk();
        $response->assertSee('Все уроки пройдены');
        $response->assertSee('data-testid="referral-loyalty-cta-course-complete"', false);
        $response->assertSee('Порекомендовать школу');
        $this->assertH1294Voice($response->getContent());
    }

    public function test_incomplete_course_hides_course_complete_cta_when_flag_on(): void
    {
        config(['features.referral_loyalty_cta' => true]);
        [$student] = $this->enrolledStudentWithCompletedCourse(completeAll: false);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Все уроки пройдены')
            ->assertDontSee('data-testid="referral-loyalty-cta-course-complete"', false);
    }

    public function test_certificate_list_hides_loyalty_cta_when_flag_off(): void
    {
        [$student] = $this->studentWithCertificate();

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Мои достижения')
            ->assertDontSee('data-testid="referral-loyalty-cta-certificate"', false)
            ->assertSee('Порекомендовать школу');
    }

    public function test_certificate_list_shows_existing_partial_when_flag_on(): void
    {
        config(['features.referral_loyalty_cta' => true]);
        [$student] = $this->studentWithCertificate();

        $response = $this->actingAs($student)
            ->get(route('student.dashboard'));

        $response->assertOk();
        $response->assertSee('Мои достижения');
        $response->assertSee('data-testid="referral-loyalty-cta-certificate"', false);
        $response->assertSee('Порекомендовать школу');
        $this->assertH1294Voice($response->getContent());
    }

    public function test_public_verify_never_shows_loyalty_cta_even_when_flag_on(): void
    {
        config(['features.referral_loyalty_cta' => true]);
        [$student, $course] = $this->studentWithCertificate();
        $cert = $student->certificates()->first();

        $this->get('/verify/'.$cert->number)
            ->assertOk()
            ->assertSee($cert->number)
            ->assertDontSee('Порекомендовать школу')
            ->assertDontSee('data-testid="referral-loyalty-cta', false);

        $this->assertFalse((bool) config('partner.enabled'));
        $this->assertSame($course->id, $cert->course_id);
    }

    /** @return array{0: User, 1: Course, 2: Lesson} */
    private function studentWithAcceptedHomework(): array
    {
        return $this->studentWithHomework(HomeworkSubmission::STATUS_ACCEPTED);
    }

    /** @return array{0: User, 1: Course, 2: Lesson} */
    private function studentWithHomework(string $status): array
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => true,
            'homework_enabled' => true,
            'homework_prompt' => 'Склоняйте ramā.',
        ]);
        $student = User::factory()->create();

        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => $status,
            'last_activity_at' => now(),
            'reviewed_at' => $status === HomeworkSubmission::STATUS_ACCEPTED ? now() : null,
        ]);

        return [$student, $course, $lesson];
    }

    /** @return array{0: User, 1: Course} */
    private function enrolledStudentWithCompletedCourse(bool $completeAll = true): array
    {
        $course = Course::factory()->create(['title' => 'Грамматика санскрита']);
        $group = Group::create(['name' => 'Поток 1']);
        $course->groups()->attach($group);

        $lessons = collect(range(1, 2))->map(fn (int $i) => Lesson::factory()->for($course)->create([
            'title' => "Урок {$i}",
            'sort_order' => $i,
            'group_id' => null,
        ]));

        $student = User::factory()->create();
        $student->groups()->attach($group);

        if ($completeAll) {
            $lessons->each(fn (Lesson $lesson) => $student->lessonProgress()->attach($lesson->id, ['is_completed' => true]));
        } else {
            $student->lessonProgress()->attach($lessons[0]->id, ['is_completed' => true]);
        }

        return [$student, $course];
    }

    /** @return array{0: User, 1: Course} */
    private function studentWithCertificate(): array
    {
        $student = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Основы санскрита']);

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'student_name' => $student->name,
            'course_title' => $course->title,
            'issued_at' => now(),
        ]);

        return [$student, $course];
    }

    private function assertH1294Voice(string $html): void
    {
        $this->assertStringNotContainsStringIgnoringCase('награда', $html);
        $this->assertStringNotContainsStringIgnoringCase('бонус', $html);
        $this->assertStringNotContainsStringIgnoringCase('заработок', $html);
    }
}
