<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H5297 — invariant mutation wave 2, seam 1 (authorization/destructive-lifecycle):
 * GatedAssetController::transcript gates a paid lesson's transcript behind the
 * same LessonGate chain as the video player (H3308). No boundary test existed
 * for this controller before this handoff.
 *
 * Invariant:
 *   - Result: a non-entitled authenticated user gets 404 for the transcript route.
 *   - Forbidden side effect: the response body never carries the transcript's
 *     content — a 404 that still leaked the file bytes into a prior middleware
 *     buffer would defeat the whole point of H3308's private-disk migration.
 *   - Persisted state: the request does not create or touch any access-grant row.
 */
class H5297GatedAssetTranscriptFenceTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private Lesson $lesson;

    private const SECRET_MARKER = 'PAID_TRANSCRIPT_SECRET_MARKER_H5297';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->course = Course::factory()->create();
        $this->group = Group::create(['name' => 'Поток '.$this->course->id]);
        $this->course->groups()->attach($this->group->id);

        $this->lesson = Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'block_number' => 1,
            'is_free' => false,
            'transcript_file' => 'transcripts/lesson-h5297.json',
        ]);

        Storage::disk('local')->put(
            'transcripts/lesson-h5297.json',
            json_encode(['text' => self::SECRET_MARKER], JSON_THROW_ON_ERROR)
        );
    }

    private function transcriptUrl(): string
    {
        return route('student.lesson.transcript', [$this->course->slug, $this->lesson->id]);
    }

    /** @test */
    public function a_non_entitled_student_gets_404_and_never_sees_the_transcript_bytes(): void
    {
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->get($this->transcriptUrl());

        $response->assertNotFound();
        $response->assertDontSee(self::SECRET_MARKER, false);
        $this->assertDatabaseCount('lesson_access_grants', 0);
    }

    /** @test */
    public function the_paying_buyer_receives_the_transcript(): void
    {
        $buyer = User::factory()->create();
        $buyer->groups()->syncWithoutDetaching([$this->group->id]);

        Payment::create([
            'user_id' => $buyer->id,
            'course_id' => $this->course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $response = $this->actingAs($buyer)->get($this->transcriptUrl());

        $response->assertOk();
        $response->assertSee(self::SECRET_MARKER, false);
    }
}
