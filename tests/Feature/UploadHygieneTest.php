<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Jobs\GenerateCertificatesArchive;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\Group;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\Design\CourseDesignAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * H3310 — гигиена публичного диска.
 *
 * Три поверхности против «пользовательский файл уезжает на /storage»:
 *  • баннеры курса — джейл расширений в CourseDesignAssetService;
 *  • сертификатные ZIP — приватный диск + staff-маршрут force-download;
 *  • CSV импорта пользователей — приватный диск.
 */
class UploadHygieneTest extends TestCase
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

    /** @test */
    public function a_php_disguise_with_jpeg_bytes_is_rejected(): void
    {
        $course = Course::factory()->create();

        try {
            $this->service()->store(
                $course,
                '16:9',
                UploadedFile::fake()->image('shell.php', 1600, 900),
                null,
                null,
                User::factory()->create(),
            );
            $this->fail('shell.php обязан быть отклонён');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('JPG', $e->getMessage());
        }

        $this->assertSame(0, CourseDesignAsset::query()->count());
        $this->assertSame([], $this->storedPublicFiles());
    }

    /** @test */
    public function the_cover_php_fixture_with_png_bytes_is_rejected(): void
    {
        // Фикстура из приёмки H3310: PNG-байты под именем cover.php.
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        try {
            $this->service()->store(
                Course::factory()->create(),
                '16:9',
                UploadedFile::fake()->createWithContent('cover.php', $pngBytes),
                null,
                null,
                User::factory()->create(),
            );
            $this->fail('cover.php обязан быть отклонён');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('отклонён', $e->getMessage());
        }

        $this->assertSame([], $this->storedPublicFiles());
    }

    /** @test */
    public function an_svg_upload_is_rejected_too(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $file = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $this->expectException(InvalidArgumentException::class);

        $this->service()->store(Course::factory()->create(), '16:9', $file, null, null, User::factory()->create());
    }

    /** @test */
    public function a_legit_upload_keeps_working_and_gets_a_generated_name(): void
    {
        $course = Course::factory()->create();

        $asset = $this->service()->store(
            $course,
            '16:9',
            UploadedFile::fake()->image('banner.jpg', 1600, 900),
            'https://disk.example/a',
            null,
            User::factory()->create(),
        );

        $this->assertMatchesRegularExpression(
            '/^course-design\/'.$course->id.'\/16x9-[A-Za-z0-9]{8}\.jpg$/u',
            $asset->path,
        );
        $this->assertStringNotContainsString('banner', $asset->path, 'имя клиента не участвует');
        Storage::disk('public')->assertExists($asset->path);
    }

    /**
     * Контент побеждает имя: JPEG с расширением .jpeg канонизируется в .jpg,
     * а не сохраняет клиентское написание.
     *
     * @test
     */
    public function a_double_extension_jpeg_canonizes_to_jpg(): void
    {
        $course = Course::factory()->create();

        $asset = $this->service()->store(
            $course,
            '16:9',
            UploadedFile::fake()->image('poster.jpeg', 1600, 900),
            null,
            null,
            User::factory()->create(),
        );

        $this->assertStringEndsWith('.jpg', $asset->path);
        Storage::disk('public')->assertExists($asset->path);
    }

    /** @test */
    public function the_certificate_archive_lands_on_the_private_disk_behind_the_staff_route(): void
    {
        [$admin, $student] = $this->seedCertificateWorld();
        $fileName = $this->runArchiveJob($admin);

        Storage::disk('local')->assertExists('archives/'.$fileName);
        Storage::disk('public')->assertMissing('archives/'.$fileName);

        $this->actingAs($admin)
            ->get('/force-download/'.$fileName)
            ->assertOk()
            ->assertDownload($fileName);

        $this->actingAs($student)
            ->get('/force-download/'.$fileName)
            ->assertForbidden();

        $this->app['auth']->logout();
        $this->get('/force-download/'.$fileName)->assertRedirect();
    }

    /** @test */
    public function the_move_command_relocates_archives_and_the_public_url_goes_404(): void
    {
        [$admin] = $this->seedCertificateWorld();

        $fileName = 'certificates_group_7_11111111-2222-3333-4444-555555555555.zip';
        Storage::disk('public')->put('archives/'.$fileName, 'legacy-zip-bytes');

        $this->artisan('archives:move-public-to-local')->assertSuccessful();

        Storage::disk('local')->assertExists('archives/'.$fileName);
        $this->assertSame('legacy-zip-bytes', Storage::disk('local')->get('archives/'.$fileName));
        Storage::disk('public')->assertMissing('archives/'.$fileName);

        $this->actingAs($admin)
            ->get('/force-download/'.$fileName)
            ->assertOk()
            ->assertDownload($fileName);

        // Прежняя прямая ссылка больше ничего не отдаёт.
        $this->get('/storage/archives/'.$fileName)->assertNotFound();
    }

    /** @test */
    public function the_move_command_is_idempotent_and_dry_run_touches_nothing(): void
    {
        $fileName = 'certificates_group_8_99999999-8888-7777-6666-555555555555.zip';
        Storage::disk('public')->put('archives/'.$fileName, 'bytes');

        $this->artisan('archives:move-public-to-local', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertExists('archives/'.$fileName);
        Storage::disk('local')->assertMissing('archives/'.$fileName);

        $this->artisan('archives:move-public-to-local')->assertSuccessful();
        $this->artisan('archives:move-public-to-local')->assertSuccessful();

        Storage::disk('local')->assertExists('archives/'.$fileName);
        Storage::disk('public')->assertMissing('archives/'.$fileName);
        $this->assertSame('bytes', Storage::disk('local')->get('archives/'.$fileName));
    }

    /** @test */
    public function the_user_csv_import_reads_and_cleans_up_on_the_private_disk(): void
    {
        $group = Group::create(['name' => 'H3310 group']);

        Storage::disk('local')->put('imports/hygiene.csv', implode("\n", [
            'Name,Email',
            'Иван Гигиена,ivan.h3310@example.test',
            'Анна Гигиена,anna.h3310@example.test',
        ]));

        $page = new ListUsers;
        $imported = $page->importFromCsv([
            'csv_file' => 'imports/hygiene.csv',
            'group_id' => $group->id,
        ]);

        $this->assertSame(2, $imported);
        $this->assertDatabaseHas('users', ['email' => 'ivan.h3310@example.test']);
        $this->assertDatabaseHas('users', ['email' => 'anna.h3310@example.test']);
        $this->assertTrue($group->users()->where('email', 'ivan.h3310@example.test')->exists());
        Storage::disk('local')->assertMissing('imports/hygiene.csv');

        $page->importFromCsv(['csv_file' => 'imports/gone.csv', 'group_id' => null]);
        $this->assertSame(2, User::where('email', 'like', '%h3310%')->count());
    }

    /**
     * Группа + студент + сертификат и свап генератора PDF: без DomPDF и
     * внешнего QR-сервиса, но с реальной записью ZIP на диск.
     *
     * @return array{0: User, 1: User}
     */
    private function seedCertificateWorld(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $student = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Hygiene group']);
        $group->users()->attach($student);
        $group->courses()->attach($course);

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'student_name' => $student->name,
            'course_title' => $course->title,
            'template' => 'gasuns',
        ]);

        $stub = new class extends CertificateService
        {
            public function generatePdf($certificate)
            {
                return new class
                {
                    public function output(): string
                    {
                        return '%PDF-1.4 hygiene-stub';
                    }
                };
            }

            public function pdfToJpeg(string $pdfData, int $dpi = 200, int $quality = 90): string
            {
                return 'jpeg-stub-bytes';
            }
        };
        $this->swap(CertificateService::class, $stub);

        return [$admin, $student];
    }

    private function runArchiveJob(User $admin): string
    {
        $groupId = (int) Group::value('id');

        $job = new GenerateCertificatesArchive($groupId, $admin->id);
        $job->handle();

        return 'certificates_group_'.$groupId.'_'.$job->operationId.'.zip';
    }

    /** @return list<string> */
    private function storedPublicFiles(): array
    {
        $disk = Storage::disk('public');

        if (! $disk->directoryExists('course-design')) {
            return [];
        }

        return $disk->allFiles('course-design');
    }
}
