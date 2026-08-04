<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateCertificatesArchive;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Group;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateCertificatesArchiveIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_redelivery_reuses_completed_archive_and_notifies_once(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        $student = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Replay-safe group']);
        $group->users()->attach($student);
        $group->courses()->attach($course);

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'student_name' => $student->name,
            'course_title' => $course->title,
            'template' => 'gasuns',
        ]);

        $operationId = '807fd9b4-702d-423f-90e2-a31cdfb546d5';
        $archivePath = "archives/certificates_group_{$group->id}_{$operationId}.zip";
        Storage::disk('public')->put($archivePath, 'completed-zip');

        $job = new GenerateCertificatesArchive($group->id, $admin->id, operationId: $operationId);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);

        $job->handle();
        $job->handle();

        Storage::disk('public')->assertExists($archivePath);
        $this->assertSame('completed-zip', Storage::disk('public')->get($archivePath));
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame($operationId, $admin->notifications()->firstOrFail()->id);
    }

    public function test_equivalent_in_flight_requests_share_a_unique_key(): void
    {
        $first = new GenerateCertificatesArchive(7, 11, 13);
        $second = new GenerateCertificatesArchive(7, 11, 13);

        $this->assertNotSame($first->operationId, $second->operationId);
        $this->assertSame($first->uniqueId(), $second->uniqueId());
    }
}
