<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Materials\TranscriptArchiver;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpZip\ZipFile;
use RuntimeException;
use Tests\TestCase;

/**
 * Выгрузка стенограмм: ZIP с папкой на курс + скачивание одной стенограммы
 * со страниц уроков.
 */
class TranscriptArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_archive_groups_transcripts_into_folders_per_course(): void
    {
        [$grammar, $hindi] = [$this->course('Грамматика', 'grammatika'), $this->course('Хинди', 'hindi')];

        $first = $this->lesson($grammar, 'Двойственное число', 'transcripts/lesson-11.json', 'local', 2);
        $second = $this->lesson($grammar, 'Сандхи', 'transcripts/lesson-12.json', 'public', 1);
        $third = $this->lesson($hindi, 'Письмо', 'transcripts/lesson-21.json', 'public', 1);
        // Урок без стенограммы в архив не попадает вовсе.
        $this->lesson($grammar, 'Без стенограммы', null, null, 3);

        $path = app(TranscriptArchiver::class)->build();
        $entries = array_keys((new ZipFile)->openFile($path)->getEntries());

        $this->assertContains($this->entry('grammatika', 1, 'Сандхи', $second), $entries);
        $this->assertContains($this->entry('grammatika', 2, 'Двойственное число', $first), $entries);
        $this->assertContains($this->entry('hindi', 1, 'Письмо', $third), $entries);
        $this->assertContains('Содержание.txt', $entries);
        $this->assertCount(4, $entries);

        $manifest = (new ZipFile)->openFile($path)->getEntryContents('Содержание.txt');
        $this->assertStringContainsString('файлов: 3', $manifest);
        $this->assertStringContainsString('Грамматика [grammatika]', $manifest);

        // Содержимое файла — то самое, что лежит на диске.
        $this->assertSame(
            'stub transcripts/lesson-11.json',
            (new ZipFile)->openFile($path)->getEntryContents($this->entry('grammatika', 2, 'Двойственное число', $first)),
        );

        @unlink($path);
    }

    public function test_archive_can_be_limited_to_one_course_and_reports_lost_files(): void
    {
        $grammar = $this->course('Грамматика', 'grammatika');
        $this->course('Хинди', 'hindi');
        $kept = $this->lesson($grammar, 'Сандхи', 'transcripts/lesson-12.json', 'public', 1);
        $lost = $this->lesson($grammar, 'Потеряшка', 'transcripts/lesson-99.json', null, 2);
        $this->lesson(Course::query()->where('slug', 'hindi')->firstOrFail(), 'Письмо', 'transcripts/lesson-21.json', 'public', 1);

        $path = app(TranscriptArchiver::class)->build($grammar);
        $zip = (new ZipFile)->openFile($path);
        $entries = array_keys($zip->getEntries());

        $this->assertContains($this->entry('grammatika', 1, 'Сандхи', $kept), $entries);
        $this->assertNotContains($this->entry('hindi', 1, 'Письмо', $lost), $entries);
        $this->assertStringContainsString('не найден на дисках', $zip->getEntryContents('Содержание.txt'));
        $this->assertStringContainsString('#'.$lost->id, $zip->getEntryContents('Содержание.txt'));

        @unlink($path);
    }

    public function test_archive_without_any_transcript_refuses_instead_of_shipping_an_empty_zip(): void
    {
        $this->lesson($this->course('Пустой', 'pustoy'), 'Урок', null, null, 1);

        $this->expectException(RuntimeException::class);
        app(TranscriptArchiver::class)->build();
    }

    public function test_only_super_admin_downloads_the_whole_archive(): void
    {
        $course = $this->course('Грамматика', 'grammatika');
        $this->lesson($course, 'Сандхи', 'transcripts/lesson-12.json', 'public', 1);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $this->get(route('lesson-materials.transcripts'))->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => Roles::SUPER_ADMIN]));
        $response = $this->get(route('lesson-materials.transcripts'));
        $response->assertOk();
        $this->assertStringContainsString('transcripts-all-', (string) $response->headers->get('content-disposition'));
    }

    public function test_single_transcript_download_is_open_to_staff_and_closed_to_students(): void
    {
        $course = $this->course('Грамматика', 'grammatika');
        $lesson = $this->lesson($course, 'Сандхи', 'transcripts/lesson-12.json', 'public', 1);
        $withoutFile = $this->lesson($course, 'Потеряшка', 'transcripts/lesson-99.json', null, 2);
        $withoutTranscript = $this->lesson($course, 'Без стенограммы', null, null, 3);

        $this->actingAs(User::factory()->create(['role' => null]));
        $this->get(route('admin.lesson.transcript', $lesson))->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $response = $this->get(route('admin.lesson.transcript', $lesson));
        $response->assertOk();
        $this->assertStringContainsString(basename($this->entry('grammatika', 1, 'Сандхи', $lesson)), (string) $response->headers->get('content-disposition'));

        $this->get(route('admin.lesson.transcript', $withoutTranscript))->assertNotFound();
        $this->get(route('admin.lesson.transcript', $withoutFile))->assertNotFound();
    }

    public function test_external_lecture_url_is_not_served_as_a_file(): void
    {
        $course = $this->course('Грамматика', 'grammatika');
        $published = $this->lesson($course, 'Опубликованная', 'https://example.test/lecture.json', null, 1);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $this->get(route('admin.lesson.transcript', $published))->assertNotFound();

        // И в архив такой урок тоже не идёт.
        $this->lesson($course, 'Сандхи', 'transcripts/lesson-12.json', 'public', 2);
        $path = app(TranscriptArchiver::class)->build();
        $entries = array_keys((new ZipFile)->openFile($path)->getEntries());
        $this->assertCount(2, $entries);
        @unlink($path);
    }

    /** Ожидаемое имя записи в архиве — тем же путём, каким его строит сервис. */
    private function entry(string $folder, int $order, string $title, Lesson $lesson): string
    {
        return sprintf('%s/%03d-%s-%d.json', $folder, $order, Str::slug($title), $lesson->id);
    }

    private function course(string $title, string $slug): Course
    {
        $teacher = Teacher::create(['name' => 'Препод '.$slug]);

        return Course::create(['title' => $title, 'slug' => $slug, 'teacher_id' => $teacher->id]);
    }

    private function lesson(Course $course, string $title, ?string $transcript, ?string $disk, int $order): Lesson
    {
        if ($transcript !== null && $disk !== null) {
            Storage::disk($disk)->put($transcript, 'stub '.$transcript);
        }

        return Lesson::create([
            'course_id' => $course->id,
            'title' => $title,
            'lesson_date' => '2026-09-'.str_pad((string) $order, 2, '0', STR_PAD_LEFT),
            'sort_order' => $order,
            'transcript_file' => $transcript,
        ]);
    }
}
