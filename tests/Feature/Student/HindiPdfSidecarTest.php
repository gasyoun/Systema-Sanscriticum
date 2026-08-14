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
use App\Services\HindiPdfSidecarWriter;
use App\Services\HindiPdfTextBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H2731 — bounded PDF → sibling .txt. Flag stays OFF.
 */
class HindiPdfSidecarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_scanned_pdf_with_ocr_backend_writes_sidecar_and_yields_items(): void
    {
        config(['features.hindi_attachment_drills' => true]);
        [, , $lesson] = $this->seedHindiLesson();
        $path = $this->putPdf('lesson_materials/scanned-handout.pdf', '');
        $lesson->forceFill(['attachments' => [$path]])->save();

        $handout = (string) file_get_contents(base_path('tests/fixtures/hindi_attachments/handout.txt'));
        $writer = new HindiPdfSidecarWriter([new FakeHindiPdfBackend($handout, 'fake-ocr')]);
        $this->app->instance(HindiPdfSidecarWriter::class, $writer);

        $result = $writer->apply($path);
        $this->assertSame(HindiPdfSidecarWriter::STATUS_WRITTEN, $result['status']);
        $this->assertSame('fake-ocr', $result['backend']);
        $this->assertTrue($result['has_devanagari']);
        $this->assertTrue($result['has_cyrillic']);
        $this->assertTrue(Storage::disk('public')->exists('lesson_materials/scanned-handout.txt'));
        $this->assertSame(
            trim($handout),
            trim((string) Storage::disk('public')->get('lesson_materials/scanned-handout.txt')),
        );

        $items = app(HindiAttachmentDrills::class)->itemsFor($lesson);
        $this->assertNotSame([], $items);
        $this->assertContains('translate', array_column($items, 'type'));
    }

    public function test_scanned_pdf_without_text_is_skipped(): void
    {
        $path = $this->putPdf('lesson_materials/blank.pdf', '');
        $writer = new HindiPdfSidecarWriter([new FakeHindiPdfBackend('', 'fake-empty')]);

        $result = $writer->apply($path);
        $this->assertSame(HindiPdfSidecarWriter::STATUS_SKIP, $result['status']);
        $this->assertSame(HindiPdfSidecarWriter::REASON_NO_TEXT_LAYER, $result['reason']);
        $this->assertFalse(Storage::disk('public')->exists('lesson_materials/blank.txt'));
    }

    public function test_over_byte_cap_is_documented_skip(): void
    {
        $path = $this->putPdf('lesson_materials/huge.pdf', 'BT /F1 12 Tf 72 120 Td (kitab) Tj ET');
        $writer = new HindiPdfSidecarWriter([new FakeHindiPdfBackend('kitab')]);

        $result = $writer->plan($path, HindiPdfSidecarWriter::DEFAULT_MAX_PAGES, 20);
        $this->assertSame(HindiPdfSidecarWriter::STATUS_SKIP, $result['status']);
        $this->assertSame(HindiPdfSidecarWriter::REASON_OVER_BYTE_CAP, $result['reason']);
        $this->assertGreaterThan(20, $result['bytes']);
    }

    public function test_no_backend_is_documented_skip(): void
    {
        $path = $this->putPdf('lesson_materials/lonely.pdf', '');
        $writer = new HindiPdfSidecarWriter([]);

        $result = $writer->plan($path);
        $this->assertSame(HindiPdfSidecarWriter::STATUS_SKIP, $result['status']);
        $this->assertSame(HindiPdfSidecarWriter::REASON_NO_BACKEND, $result['reason']);
    }

    public function test_dry_run_command_does_not_write(): void
    {
        [, , $lesson] = $this->seedHindiLesson();
        $path = $this->putPdf('homework-prompts/kostina.pdf', '');
        $lesson->forceFill(['homework_attachments' => [$path]])->save();

        $writer = new HindiPdfSidecarWriter([new FakeHindiPdfBackend('«kitāb» — это книга')]);
        $this->app->instance(HindiPdfSidecarWriter::class, $writer);

        $this->artisan('hindi:pdf-sidecar', [
            'lesson' => $lesson->id,
            '--json' => true,
        ])->assertSuccessful();

        $this->assertFalse(Storage::disk('public')->exists('homework-prompts/kostina.txt'));

        $this->artisan('hindi:pdf-sidecar', [
            'lesson' => $lesson->id,
            '--apply' => true,
            '--json' => true,
        ])->assertSuccessful();

        $this->assertTrue(Storage::disk('public')->exists('homework-prompts/kostina.txt'));
    }

    public function test_flag_default_stays_off(): void
    {
        $this->assertFalse((bool) config('features.hindi_attachment_drills'));
    }

    public function test_existing_sidecar_is_left_alone(): void
    {
        $path = $this->putPdf('lesson_materials/has-sidecar.pdf', '');
        Storage::disk('public')->put('lesson_materials/has-sidecar.txt', 'already');
        $writer = new HindiPdfSidecarWriter([new FakeHindiPdfBackend('new text')]);

        $result = $writer->apply($path);
        $this->assertSame(HindiPdfSidecarWriter::STATUS_EXISTS, $result['status']);
        $this->assertSame('already', Storage::disk('public')->get('lesson_materials/has-sidecar.txt'));
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedHindiLesson(): array
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-pdf']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'Hindi-3-pdf']);
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

    private function putPdf(string $path, string $contentStream): string
    {
        Storage::disk('public')->put($path, $this->onePagePdf($contentStream));

        return $path;
    }

    private function onePagePdf(string $contentStream): string
    {
        $len = strlen($contentStream);
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            "4 0 obj << /Length {$len} >> stream\n{$contentStream}\nendstream\nendobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        $header = "%PDF-1.4\n";
        $body = '';
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($header) + strlen($body);
            $body .= $obj;
        }
        $xrefPos = strlen($header) + strlen($body);
        $xref = "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $header.$body.$xref."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";
    }
}

/**
 * @internal
 */
final class FakeHindiPdfBackend implements HindiPdfTextBackend
{
    public function __construct(
        private readonly string $text,
        private readonly string $label = 'fake',
        private readonly bool $present = true,
    ) {}

    public function name(): string
    {
        return $this->label;
    }

    public function available(): bool
    {
        return $this->present;
    }

    public function extract(string $absolutePdf, int $maxPages): string
    {
        return $this->text;
    }
}
