<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\User;
use App\Services\Design\CourseDesignArchiver;
use App\Services\Design\CourseDesignAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpZip\ZipFile;
use Tests\TestCase;

/**
 * Учёт работы дизайнера: слоты баннеров курса (16:9 / 9:16 / 4:3) + PSD.
 *
 * Главный инвариант — «ровно один файл на формат»: повторная загрузка ЗАМЕЩАЕТ
 * слот и убирает прежний файл с диска, а не плодит вторую строку и мусор.
 */
class CourseDesignAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    private function service(): CourseDesignAssetService
    {
        return app(CourseDesignAssetService::class);
    }

    private function image(int $w = 1600, int $h = 900, string $name = 'banner.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /** @test */
    public function it_creates_a_slot_and_stores_the_image(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $asset = $this->service()->store($course, '16:9', $this->image(), 'https://disk.example/psd', null, $actor);

        $this->assertSame('16:9', $asset->format);
        $this->assertSame(1600, $asset->width);
        $this->assertSame(900, $asset->height);
        $this->assertSame($actor->id, $asset->uploaded_by_user_id);
        Storage::disk('public')->assertExists($asset->path);
        $this->assertStringContainsString('course-design/'.$course->id.'/16x9-', $asset->path);
    }

    /** @test */
    public function reuploading_a_format_replaces_the_slot_and_deletes_the_old_file(): void
    {
        $course = Course::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $old = $this->service()->store($course, '16:9', $this->image(), 'https://disk.example/a', null, $first);
        $oldPath = $old->path;

        $new = $this->service()->store($course, '16:9', $this->image(1920, 1080), 'https://disk.example/b', null, $second);

        // Одна строка на пару (курс, формат) — держится unique-индексом.
        $this->assertSame(1, CourseDesignAsset::where('course_id', $course->id)->where('format', '16:9')->count());
        $this->assertSame($old->id, $new->id);

        // Прежний файл не остался мусором на диске.
        $this->assertNotSame($oldPath, $new->path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($new->path);

        // «Кто/когда» показывают последнего, а не первого.
        $this->assertSame($second->id, $new->uploaded_by_user_id);
    }

    /** @test */
    public function three_formats_live_side_by_side_on_one_course(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        foreach (['16:9', '9:16', '4:3'] as $format) {
            $this->service()->store($course, $format, $this->image(), 'https://disk.example/'.$format, null, $actor);
        }

        $course->refresh()->load('designAssets');
        $readiness = $course->designReadiness();

        $this->assertSame(3, $readiness['done']);
        $this->assertSame([], $readiness['missing']);
    }

    /** @test */
    public function a_partially_filled_course_reports_the_missing_formats(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $this->service()->store($course, '16:9', $this->image(), 'https://disk.example/a', null, $actor);

        $course->refresh()->load('designAssets');

        $this->assertSame(['9:16', '4:3'], $course->designReadiness()['missing']);
    }

    /** @test */
    public function an_unknown_format_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->store(
            Course::factory()->create(),
            '21:9',
            $this->image(),
            'https://disk.example/a',
            null,
            User::factory()->create(),
        );
    }

    /** @test */
    public function a_new_slot_requires_an_image(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->store(
            Course::factory()->create(),
            '16:9',
            null,
            'https://disk.example/a',
            null,
            User::factory()->create(),
        );
    }

    /** @test */
    public function an_existing_slot_can_be_updated_with_the_psd_link_alone(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $asset = $this->service()->store($course, '16:9', $this->image(), 'https://disk.example/old', null, $actor);
        $path = $asset->path;

        $updated = $this->service()->store($course, '16:9', null, 'https://disk.example/new', null, $actor);

        $this->assertSame('https://disk.example/new', $updated->psd_url);
        $this->assertSame($path, $updated->path, 'картинку не трогали — она обязана остаться');
        Storage::disk('public')->assertExists($path);
    }

    /** @test */
    public function a_mismatched_ratio_is_flagged_but_still_saved(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $ok = $this->service()->store($course, '16:9', $this->image(1920, 1080), 'https://disk.example/a', null, $actor);
        $this->assertTrue($ok->ratioMatches(), '1920×1080 — ровно 16:9');

        $near = $this->service()->store($course, '9:16', $this->image(1200, 2130), 'https://disk.example/n', null, $actor);
        $this->assertTrue($near->ratioMatches(), '1200×2130 — 9:16 в пределах допуска, придираться не надо');

        // Реальный сценарий ошибки: дизайнер залил широкий баннер в слот 4:3.
        $off = $this->service()->store($course, '4:3', $this->image(1600, 900), 'https://disk.example/b', null, $actor);
        $this->assertFalse($off->ratioMatches(), '16:9-картинка в слоте 4:3 — расхождение');
        $this->assertNotNull($off->path, 'учёт не блокирует загрузку из-за пропорции');
    }

    /** @test */
    public function the_psd_file_is_optional_and_lands_on_the_private_disk(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $asset = $this->service()->store(
            $course,
            '16:9',
            $this->image(),
            'https://disk.example/a',
            UploadedFile::fake()->create('source.psd', 64),
            $actor,
        );

        $this->assertSame('local', $asset->psd_disk);
        Storage::disk('local')->assertExists($asset->psd_path);
        Storage::disk('public')->assertMissing((string) $asset->psd_path);
        $this->assertTrue($asset->hasPsd());
    }

    /** @test */
    public function clearing_the_psd_file_keeps_the_image_and_the_link(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $asset = $this->service()->store(
            $course,
            '16:9',
            $this->image(),
            'https://disk.example/a',
            UploadedFile::fake()->create('source.psd', 32),
            $actor,
        );
        $psdPath = (string) $asset->psd_path;

        $this->service()->clearPsdFile($asset);
        $asset->refresh();

        Storage::disk('local')->assertMissing($psdPath);
        $this->assertNull($asset->psd_path);
        $this->assertSame('https://disk.example/a', $asset->psd_url);
        Storage::disk('public')->assertExists($asset->path);
        $this->assertTrue($asset->hasPsd(), 'ссылка осталась — исходник по-прежнему учтён');
    }

    /** @test */
    public function deleting_a_slot_removes_the_row_and_both_files(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $asset = $this->service()->store(
            $course,
            '16:9',
            $this->image(),
            'https://disk.example/a',
            UploadedFile::fake()->create('source.psd', 16),
            $actor,
        );
        $imagePath = $asset->path;
        $psdPath = (string) $asset->psd_path;

        $this->service()->delete($asset);

        $this->assertDatabaseMissing('course_design_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing($imagePath);
        Storage::disk('local')->assertMissing($psdPath);
    }

    /** @test */
    public function the_snapshot_counts_only_active_courses(): void
    {
        $active = Course::factory()->create(['is_active' => true]);
        Course::factory()->create(['is_active' => false]);
        $actor = User::factory()->create();

        $this->service()->store($active, '16:9', $this->image(), 'https://disk.example/a', null, $actor);

        $snapshot = $this->service()->snapshot();

        $this->assertSame(1, $snapshot['courses'], 'архивный курс не должен вечно красить бейдж');
        $this->assertSame(0, $snapshot['complete']);
        $this->assertSame(2, $snapshot['missingSlots']);
        $this->assertSame(0, $snapshot['withoutPsd'], 'ссылка на облако считается учтённым исходником');
    }

    /** @test */
    public function the_archive_holds_the_banners_the_psd_file_and_the_links_list(): void
    {
        $course = Course::factory()->create();
        $actor = User::factory()->create();

        $this->service()->store(
            $course,
            '16:9',
            $this->image(1600, 900, 'wide.jpg'),
            'https://disk.example/wide',
            UploadedFile::fake()->create('wide.psd', 8),
            $actor,
        );
        $this->service()->store($course, '9:16', $this->image(900, 1600, 'tall.jpg'), 'https://disk.example/tall', null, $actor);

        $archiver = app(CourseDesignArchiver::class);
        $token = $archiver->build($course->refresh());
        $path = $archiver->pathForToken($token);

        $this->assertNotNull($path);

        $zip = new ZipFile;
        $zip->openFile($path);
        $names = array_keys($zip->getEntries());
        $links = $zip->getEntryContents('Исходники PSD (ссылки).txt');
        $zip->close();
        @unlink($path);

        $this->assertContains($course->slug.'-16x9.jpg', $names);
        $this->assertContains($course->slug.'-9x16.jpg', $names);
        $this->assertContains('PSD/'.$course->slug.'-16x9.psd', $names);
        $this->assertStringContainsString('https://disk.example/wide', $links);
        $this->assertStringContainsString('https://disk.example/tall', $links);
    }

    /** @test */
    public function an_empty_course_gives_a_clear_error_instead_of_a_broken_archive(): void
    {
        $this->expectException(\RuntimeException::class);

        app(CourseDesignArchiver::class)->build(Course::factory()->create());
    }

    /** @test */
    public function the_archive_token_cannot_escape_its_directory(): void
    {
        $archiver = app(CourseDesignArchiver::class);

        $this->assertNull($archiver->pathForToken('../../../.env'));
        $this->assertNull($archiver->pathForToken('nope.zip'));
    }
}
