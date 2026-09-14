<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MyMaterials;
use App\Filament\Resources\CourseMaterialSubmissionResource;
use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\CourseMaterialSubmission;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseMaterialSubmissionService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4325 (H4310 3/3) — «Мои материалы»: препод шлёт видео-анонс/бейдж 4:3/
 * конспект, куратор ведёт очередь accepted → in_progress → published.
 * Анти-цель: заявка не публикует себя сама — публикует только куратор.
 */
class CourseMaterialSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Teacher $teacher;

    private User $teacherUser;

    private User $curator;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.telegram.curators_chat_id' => '-1001234567890']);

        $this->teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $this->teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $this->teacher->id]);
        $this->curator = User::factory()->create(['role' => Roles::ADMIN]);
        $this->course = Course::factory()->create(['teacher_id' => $this->teacher->id]);
    }

    public function test_submit_creates_an_accepted_submission_and_notifies_curators(): void
    {
        $service = app(CourseMaterialSubmissionService::class);

        $submission = $service->submit(
            $this->course,
            $this->teacherUser,
            'https://www.youtube.com/watch?v=abc12345678',
            null,
            'Конспект первой лекции.',
        );

        $this->assertSame(CourseMaterialSubmission::STATUS_ACCEPTED, $submission->status);
        $this->assertSame($this->teacherUser->id, $submission->submitted_by_user_id);

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job): bool => str_contains($job->text, 'Мои материалы'));
    }

    public function test_submit_never_touches_live_course_fields(): void
    {
        $service = app(CourseMaterialSubmissionService::class);

        $service->submit(
            $this->course,
            $this->teacherUser,
            'https://www.youtube.com/watch?v=abc12345678',
            $this->fakeBadge(),
            'Черновой конспект.',
        );

        $this->course->refresh();

        $this->assertNull($this->course->video_announce_url);
        $this->assertNull($this->course->teacher_notes);
        $this->assertDatabaseCount('course_design_assets', 0);
    }

    public function test_repeated_submit_updates_the_same_open_row_and_notifies_again(): void
    {
        $service = app(CourseMaterialSubmissionService::class);

        $first = $service->submit($this->course, $this->teacherUser, 'https://www.youtube.com/watch?v=abc12345678', null, 'v1');
        $second = $service->submit($this->course, $this->teacherUser, 'https://www.youtube.com/watch?v=abc12345678', null, 'v2');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('v2', $second->fresh()->notes);
        $this->assertDatabaseCount('course_material_submissions', 1);

        Queue::assertPushed(SendTelegramChatMessageJob::class, 2);
    }

    public function test_publish_writes_into_course_and_design_asset_and_leaves_blank_fields_untouched(): void
    {
        $service = app(CourseMaterialSubmissionService::class);

        // Курс уже публиковал видео раньше — заявка присылает только бейдж и конспект.
        $this->course->update(['video_announce_url' => 'https://www.youtube.com/watch?v=already12345']);

        $submission = $service->submit($this->course, $this->teacherUser, null, $this->fakeBadge(), 'Опубликованный конспект.');

        $published = $service->publish($submission, $this->curator);

        $this->assertSame(CourseMaterialSubmission::STATUS_PUBLISHED, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertSame($this->curator->id, $published->reviewed_by_user_id);

        $this->course->refresh();
        // Пустое поле заявки не затёрло уже опубликованное видео.
        $this->assertSame('https://www.youtube.com/watch?v=already12345', $this->course->video_announce_url);
        $this->assertSame('Опубликованный конспект.', $this->course->teacher_notes);

        $asset = CourseDesignAsset::query()->where('course_id', $this->course->id)->where('format', '4:3')->first();
        $this->assertNotNull($asset);
        $this->assertSame($this->teacherUser->id, $asset->uploaded_by_user_id);
        $this->assertSame($this->course->catalogBadgeUrl(), $asset->imageUrl());
    }

    public function test_setstatus_moves_through_the_queue_and_publish_transitions_status(): void
    {
        $service = app(CourseMaterialSubmissionService::class);
        $submission = $service->submit($this->course, $this->teacherUser, 'https://www.youtube.com/watch?v=abc12345678', null, null);

        $service->setStatus($submission, CourseMaterialSubmission::STATUS_IN_PROGRESS, $this->curator);
        $this->assertSame(CourseMaterialSubmission::STATUS_IN_PROGRESS, $submission->fresh()->status);

        $service->setStatus($submission, CourseMaterialSubmission::STATUS_PUBLISHED, $this->curator);
        $this->assertSame(CourseMaterialSubmission::STATUS_PUBLISHED, $submission->fresh()->status);
    }

    public function test_new_cycle_starts_after_publish(): void
    {
        $service = app(CourseMaterialSubmissionService::class);
        $first = $service->submit($this->course, $this->teacherUser, 'https://www.youtube.com/watch?v=abc12345678', null, 'first');
        $service->publish($first, $this->curator);

        $second = $service->submit($this->course, $this->teacherUser, 'https://www.youtube.com/watch?v=def98765432', null, 'second');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(CourseMaterialSubmission::STATUS_ACCEPTED, $second->status);
        $this->assertDatabaseCount('course_material_submissions', 2);
    }

    public function test_teacher_page_is_only_visible_to_teachers_and_admin_like_staff(): void
    {
        $this->actingAs($this->teacherUser);
        $this->assertTrue(MyMaterials::canAccess());

        $this->actingAs($this->curator);
        $this->assertTrue(MyMaterials::canAccess());
    }

    public function test_curator_queue_is_hidden_from_teachers(): void
    {
        $this->actingAs($this->teacherUser);
        $this->assertFalse(CourseMaterialSubmissionResource::canViewAny());

        $this->actingAs($this->curator);
        $this->assertTrue(CourseMaterialSubmissionResource::canViewAny());
    }

    private function fakeBadge(): UploadedFile
    {
        return UploadedFile::fake()->image('badge.jpg', 800, 600);
    }
}
