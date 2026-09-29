<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4978 — smoke coverage for the 3 previously-untested routes moved into
 * App\Http\Controllers\Concerns\StudentCourseContentConcerns /
 * StudentCertificateConcerns during the StudentController.php split
 * (H5245 recovery: Phase 2 layout superseded the Phase 1 Manages* traits).
 * Not exhaustive behavior tests — these exist to prove the routes still
 * resolve and respond sanely after the move.
 */
class StudentControllerPhase1SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    public function test_download_course_materials_streams_a_zip_for_an_unlocked_student(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток '.$course->id]);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->syncWithoutDetaching([$group->id]);

        Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 5500,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]);

        $response = $this->actingAs($student)
            ->get(route('student.course.materials.download', $course->slug));

        $response->assertOk();
        $this->assertStringContainsString('zip', (string) $response->headers->get('Content-Type'));
    }

    public function test_download_course_materials_redirects_back_with_error_for_a_locked_student(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток '.$course->id]);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->syncWithoutDetaching([$group->id]);

        $response = $this->actingAs($student)
            ->get(route('student.course.materials.download', $course->slug));

        $response->assertRedirect();
        $this->assertNotEmpty(session('error'));
    }

    public function test_download_certificate_streams_a_pdf(): void
    {
        $student = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Основы санскрита']);

        $certificate = Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'student_name' => $student->name,
            'course_title' => $course->title,
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($student)
            ->get(route('student.certificate.download', $certificate->id));

        $response->assertOk();
        $this->assertStringContainsString('pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_download_certificate_image_route_resolves_without_crashing(): void
    {
        // H4978 scope: prove the moved route reaches the trait method safely.
        // JPEG generation needs the imagick extension (not guaranteed on every
        // box) — CertificateService::generateJpegBytes throws RuntimeException
        // when absent, and the controller already handles that via back()
        // with an error, which this test accepts as a valid non-crash outcome.
        $student = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Основы санскрита']);

        $certificate = Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'student_name' => $student->name,
            'course_title' => $course->title,
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($student)
            ->get(route('student.certificate.download.jpg', $certificate->id));

        $this->assertContains($response->getStatusCode(), [200, 302]);

        if ($response->getStatusCode() === 200) {
            $this->assertStringContainsString('jpeg', (string) $response->headers->get('Content-Type'));
        } else {
            $this->assertNotEmpty(session('error'));
        }
    }
}
