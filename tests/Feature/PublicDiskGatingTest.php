<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\User;
use App\Support\TranscriptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H3308 — контент урока (стенограмма, материалы, справочные файлы ДЗ)
 * живёт на приватном диске и отдаётся только гейт-роутами плеера.
 */
class PublicDiskGatingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-lesson-sync-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.lesson_sync.secret', self::SECRET);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_n8n_ingest_writes_transcript_to_local_not_public(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();

        $response = $this->postJson("/api/lessons/{$lesson->id}/transcript", $this->deepgramPayload(), [
            'X-Secret-Key' => self::SECRET,
        ]);

        $response->assertOk();
        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_parser_reads_from_local_disk(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode($this->deepgramPayload(), JSON_UNESCAPED_UNICODE));

        $sentences = TranscriptParser::sentencesFromStoredFile($path);

        $this->assertCount(1, $sentences);
        $this->assertSame('Привет мир.', $sentences[0]['text']);
    }

    public function test_entitled_student_streams_transcript(): void
    {
        $lesson = Lesson::factory()->free()->for(Course::factory())->create([ // group_id = null → виден всем
            'transcript_file' => null, // путь проставим после записи файла
        ]);
        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode($this->deepgramPayload(), JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        $student = User::factory()->create();

        $this->actingAs($student)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/transcript")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_paid_lesson_transcript_is_404_without_purchase_or_grant(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create(); // is_free=false
        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode($this->deepgramPayload(), JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/transcript")
            ->assertNotFound();
    }

    public function test_personal_grant_opens_transcript_of_paid_lesson(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode($this->deepgramPayload(), JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        $granted = User::factory()->create();
        LessonAccessGrant::query()->create([
            'user_id' => $granted->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'reason' => 'trial',
        ]);

        $this->actingAs($granted)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/transcript")
            ->assertOk();
    }

    public function test_guest_cannot_fetch_transcript(): void
    {
        $lesson = Lesson::factory()->free()->for(Course::factory())->create();

        $this->get("/c/{$lesson->course->slug}/u/{$lesson->id}/transcript")
            ->assertRedirect(route('login'));
    }

    public function test_material_download_gated_and_membership_checked(): void
    {
        $lesson = Lesson::factory()->free()->for(Course::factory())->create([
            'attachments' => ['lesson-materials/material-66a1.pdf'],
        ]);
        Storage::disk('local')->put('lesson-materials/material-66a1.pdf', 'PDFBYTES');

        $student = User::factory()->create();

        // Подмена пути (URL-encoded слэш) режется констрейнтом роута → 404.
        $this->actingAs($student)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/materials/lesson-materials%2Fmaterial-66a1.pdf")
            ->assertNotFound();

        $this->actingAs($student)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/materials/not-listed.pdf")
            ->assertNotFound();

        $this->actingAs($student)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/materials/material-66a1.pdf")
            ->assertOk();
    }

    public function test_homework_ref_download_gated(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create([
            'homework_attachments' => ['homework-prompts/ref-77b2.pdf'],
        ]);
        Storage::disk('local')->put('homework-prompts/ref-77b2.pdf', 'REFPDF');

        $granted = User::factory()->create();
        LessonAccessGrant::query()->create([
            'user_id' => $granted->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'reason' => 'trial',
        ]);
        $outsider = User::factory()->create();

        $this->actingAs($granted)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/homework-files/ref-77b2.pdf")
            ->assertOk();

        $this->actingAs($outsider)
            ->get("/c/{$lesson->course->slug}/u/{$lesson->id}/homework-files/ref-77b2.pdf")
            ->assertNotFound();
    }

    public function test_privatize_command_moves_files_and_removes_public_originals(): void
    {
        Storage::disk('public')->put('transcripts/lesson-1.json', '{}');
        Storage::disk('public')->put('lesson-materials/a.pdf', 'A');
        Storage::disk('public')->put('lectures/published.html', '<p>keep</p>'); // не наш префикс

        // Dry-run: ничего не меняет.
        $this->artisan('lessons:privatize-gated-assets')->assertSuccessful();
        Storage::disk('public')->assertExists('transcripts/lesson-1.json');
        Storage::disk('local')->assertMissing('transcripts/lesson-1.json');

        $this->artisan('lessons:privatize-gated-assets', ['--apply' => true])->assertSuccessful();

        Storage::disk('local')->assertExists('transcripts/lesson-1.json');
        Storage::disk('local')->assertExists('lesson-materials/a.pdf');
        Storage::disk('public')->assertMissing('transcripts/lesson-1.json');
        Storage::disk('public')->assertMissing('lesson-materials/a.pdf');
        // Чужие префиксы команда не трогает.
        Storage::disk('public')->assertExists('lectures/published.html');

        // Идемпотентность: второй прогон — пусто.
        $this->artisan('lessons:privatize-gated-assets', ['--apply' => true])->assertSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function deepgramPayload(): array
    {
        return [
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => [
                            ['word' => 'Привет', 'start' => 0.0, 'end' => 0.5],
                            ['word' => 'мир.', 'start' => 0.6, 'end' => 1.0],
                        ],
                    ]],
                ]],
            ],
        ];
    }
}
