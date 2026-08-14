<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiAttachmentTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * H2444 — Hindi drills from files already on the lesson.
 */
class HindiAttachmentDrillsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_flag_off_returns_404_when_transcript_flag_also_off(): void
    {
        config([
            'features.hindi_attachment_drills' => false,
            'features.hindi_transcript_drills' => false,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithTxt();

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertNotFound();
    }

    public function test_fixture_txt_yields_items_and_checks_answer(): void
    {
        config([
            'features.hindi_attachment_drills' => true,
            'features.hindi_transcript_drills' => false,
            'features.hindi_programme_playlist' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithTxt();

        $html = $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="hindi-attachment-handouts"', false)
            ->assertSee('data-testid="hindi-drill-item"', false)
            ->assertSee('Как по-русски: kitāb', false)
            ->getContent();

        $this->assertStringContainsString('handout.txt', $html);

        $items = app(HindiAttachmentDrills::class)->itemsFor($lesson);
        $this->assertNotSame([], $items);
        $translate = collect($items)->firstWhere('type', 'translate');
        $this->assertNotNull($translate);

        $this->actingAs($user)
            ->postJson(route('student.lesson.drills.check', [$course->slug, $lesson->id]), [
                'item_id' => $translate['id'],
                'answer' => 'Книга!',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'item_id' => $translate['id']]);
    }

    public function test_fixture_docx_yields_items(): void
    {
        config(['features.hindi_attachment_drills' => true]);
        [$user, $course, $lesson] = $this->seedHindiLesson();
        $path = $this->putDocx('lesson_materials/docx-handout.docx', implode("\n", [
            '«kitāb» — это книга',
            'Повторите слово पानी ещё раз.',
        ]));
        $lesson->forceFill(['attachments' => [$path]])->save();

        $items = app(HindiAttachmentDrills::class)->itemsFor($lesson);
        $this->assertNotSame([], $items);
        $this->assertContains('translate', array_column($items, 'type'));

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('Как по-русски: kitāb', false);
    }

    public function test_binary_pdf_is_skipped_and_handout_link_shown(): void
    {
        config([
            'features.hindi_attachment_drills' => true,
            'features.hindi_transcript_drills' => false,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLesson();
        $path = 'lesson_materials/kostina.pdf';
        Storage::disk('public')->put($path, "%PDF-1.4\n%binary-not-text");
        $lesson->forceFill(['attachments' => [$path]])->save();

        $items = app(HindiAttachmentDrills::class)->itemsFor($lesson);
        $this->assertSame([], $items);

        $handouts = app(HindiAttachmentDrills::class)->handoutsFor($lesson);
        $this->assertCount(1, $handouts);
        $this->assertFalse($handouts[0]['extractable']);
        $this->assertSame(HindiAttachmentTextExtractor::REASON_PDF_NO_EXTRACT, $handouts[0]['reason']);

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="hindi-attachment-handouts"', false)
            ->assertSee('data-testid="hindi-attachment-pdf-skip"', false)
            ->assertSee('kostina.pdf', false)
            ->assertDontSee('Как по-русски:', false);
    }

    public function test_pdf_sibling_txt_is_used_as_extract_path(): void
    {
        config(['features.hindi_attachment_drills' => true]);
        [, , $lesson] = $this->seedHindiLesson();
        Storage::disk('public')->put('lesson_materials/handout.pdf', "%PDF-1.4\n");
        Storage::disk('public')->put(
            'lesson_materials/handout.txt',
            (string) file_get_contents(base_path('tests/fixtures/hindi_attachments/handout.txt')),
        );
        $lesson->forceFill(['attachments' => ['lesson_materials/handout.pdf']])->save();

        $items = app(HindiAttachmentDrills::class)->itemsFor($lesson);
        $this->assertNotSame([], $items);
        $this->assertContains('translate', array_column($items, 'type'));
    }

    public function test_homework_txt_is_treated_as_on_lesson_file(): void
    {
        config(['features.hindi_attachment_drills' => true]);
        [, , $lesson] = $this->seedHindiLesson();
        $path = $this->putFixtureTxt('homework-prompts/vocab.txt');
        $lesson->forceFill(['attachments' => [], 'homework_attachments' => [$path]])->save();

        $items = app(HindiAttachmentDrills::class)->itemsFor($lesson);
        $this->assertNotSame([], $items);
    }

    public function test_sanskrit_lesson_is_not_served(): void
    {
        config(['features.hindi_attachment_drills' => true]);
        $sanskrit = Category::factory()->create(['slug' => 'sanskrit']);
        $course = Course::factory()->create(['title' => 'Санскрит гр. 1', 'slug' => 'sa-att']);
        $course->categories()->attach($sanskrit->id);
        $group = Group::create(['name' => 'Sa-att']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Санскрит 1',
            'is_published' => true,
            'is_free' => false,
            'block_number' => 1,
            'attachments' => [$this->putFixtureTxt()],
        ]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertNotFound();
    }

    public function test_playlist_and_lesson_show_entry_for_handout_only_pdf(): void
    {
        config([
            'features.hindi_attachment_drills' => true,
            'features.hindi_transcript_drills' => false,
            'features.hindi_programme_playlist' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLesson();
        Storage::disk('public')->put('lesson_materials/only.pdf', "%PDF-1.4\n");
        $lesson->forceFill(['attachments' => ['lesson_materials/only.pdf']])->save();

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee('data-testid="hindi-playlist-drills"', false);

        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="hindi-transcript-drills-cta"', false);
    }

    public function test_census_and_probe_work_with_flag_off(): void
    {
        config(['features.hindi_attachment_drills' => false]);
        [, , $lesson] = $this->seedHindiLessonWithTxt();

        $this->artisan('hindi:attachments-census', ['--json' => true])
            ->assertSuccessful();

        $this->artisan('hindi:drills-probe', ['lesson' => $lesson->id, '--json' => true])
            ->assertSuccessful();

        $this->assertGreaterThanOrEqual(1, count(app(HindiAttachmentDrills::class)->itemsFor($lesson)));
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedHindiLessonWithTxt(): array
    {
        [$user, $course, $lesson] = $this->seedHindiLesson();
        $lesson->forceFill(['attachments' => [$this->putFixtureTxt()]])->save();

        return [$user, $course, $lesson];
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedHindiLesson(): array
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-att']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'Hindi-3-att']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
            'attachments' => [],
        ]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        return [$user, $course, $lesson];
    }

    private function putFixtureTxt(string $path = 'lesson_materials/handout.txt'): string
    {
        Storage::disk('public')->put(
            $path,
            (string) file_get_contents(base_path('tests/fixtures/hindi_attachments/handout.txt')),
        );

        return $path;
    }

    private function putDocx(string $path, string $text): string
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h2444-'.uniqid('', true).'.docx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $paragraphs = '';
        foreach (preg_split('/\n+/', $escaped) ?: [] as $line) {
            $paragraphs .= '<w:p><w:r><w:t xml:space="preserve">'.$line.'</w:t></w:r></w:p>';
        }
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'</w:body></w:document>',
        );
        $zip->close();
        Storage::disk('public')->put($path, (string) file_get_contents($tmp));
        @unlink($tmp);

        return $path;
    }
}
