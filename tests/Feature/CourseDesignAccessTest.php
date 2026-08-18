<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CourseDesignAssets;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\User;
use App\Services\Design\CourseDesignAssetService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Доступы к «Дизайну курсов»: admin + manager (super_admin — через RoleGate).
 *
 * Отдельно закреплено, что выдача PSD НЕ висит на /force-download: тот пускает
 * по legacy-флагу is_admin, и manager — ради которого страница и делалась —
 * через него не прошёл бы.
 */
class CourseDesignAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        // Предмет набора — страница «Дизайн курсов» и её действия, а не
        // автоперевод обложек в WebP (H3082). Наблюдатель переписывал бы
        // `courses/cover.jpg` при $course->update(), и проверка «витрина не
        // тронута ДЕЙСТВИЕМ переноса» сравнивала бы уже не то. Тот же приём и
        // по той же причине применён в CourseCoverImportTest.
        config()->set('media.webp.enabled', false);
    }

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @test */
    public function admin_manager_and_super_admin_reach_the_page(): void
    {
        foreach ([Roles::ADMIN, Roles::MANAGER, Roles::SUPER_ADMIN] as $role) {
            $this->actingAs($this->user($role));
            $this->assertTrue(CourseDesignAssets::canAccess(), $role.' должен видеть страницу');
        }
    }

    /** @test */
    public function teacher_accountant_and_student_do_not_reach_the_page(): void
    {
        foreach ([Roles::TEACHER, Roles::ACCOUNTANT, null] as $role) {
            $this->actingAs($this->user($role));
            $this->assertFalse(CourseDesignAssets::canAccess(), ($role ?? 'студент').' не должен видеть страницу');
        }
    }

    /**
     * Рендер матрицы целиком: колонки форматов, бейджи и подсказки собираются
     * из замыканий, которые падают только при отрисовке, а не при загрузке
     * класса. Курс с дырой и курс без единого баннера — обе ветки.
     *
     * @test
     */
    public function the_matrix_renders_for_a_manager(): void
    {
        $filled = Course::factory()->create(['title' => 'Курс с баннером', 'is_active' => true]);
        Course::factory()->create(['title' => 'Курс без баннеров', 'is_active' => true]);

        app(CourseDesignAssetService::class)->store(
            $filled,
            '16:9',
            UploadedFile::fake()->image('b.jpg', 1600, 900),
            'https://disk.example/psd',
            null,
            $this->user(Roles::ADMIN),
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->assertOk()
            ->assertSee('Курс с баннером')
            ->assertSee('Курс без баннеров')
            ->assertSee('16:9');
    }

    /**
     * Главный рабочий режим страницы: «покажи, где дыры». Фильтр обязан отсечь
     * полностью укомплектованный курс и оставить курс с недостающим форматом.
     *
     * @test
     */
    public function the_incomplete_filter_shows_exactly_the_courses_with_gaps(): void
    {
        $complete = Course::factory()->create(['title' => 'Полностью готов', 'is_active' => true]);
        $partial = Course::factory()->create(['title' => 'Не хватает форматов', 'is_active' => true]);
        $actor = $this->user(Roles::ADMIN);
        $service = app(CourseDesignAssetService::class);

        foreach ((array) config('design_assets.formats') as $format) {
            $service->store($complete, (string) $format, UploadedFile::fake()->image('b.jpg', 1600, 900), 'https://disk.example/x', null, $actor);
        }
        $service->store($partial, '16:9', UploadedFile::fake()->image('b.jpg', 1600, 900), 'https://disk.example/y', null, $actor);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->filterTable('incomplete', true)
            ->assertCanSeeTableRecords([$partial])
            ->assertCanNotSeeTableRecords([$complete]);

        Livewire::test(CourseDesignAssets::class)
            ->filterTable('incomplete', false)
            ->assertCanSeeTableRecords([$complete])
            ->assertCanNotSeeTableRecords([$partial]);
    }

    /**
     * Действие загрузки целиком: модалка → сервис → строка в БД → файл на диске.
     * Проверяет проводку формы, а не только сервис (его гоняет отдельный тест).
     *
     * @test
     */
    public function the_upload_action_fills_a_slot(): void
    {
        $course = Course::factory()->create(['is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->callTableAction('upload', $course, [
                'format' => '9:16',
                'image' => [UploadedFile::fake()->image('story.jpg', 1080, 1920)],
                'psd_url' => 'https://disk.example/story',
            ])
            ->assertHasNoTableActionErrors();

        $asset = CourseDesignAsset::where('course_id', $course->id)->where('format', '9:16')->first();

        $this->assertNotNull($asset, 'слот должен появиться после действия');
        $this->assertSame('https://disk.example/story', $asset->psd_url);
        Storage::disk('public')->assertExists($asset->path);
    }

    /**
     * Ссылка на исходник необязательна: картинку заливают раньше, чем дизайнер
     * выложил PSD в облако, и требование ссылки блокировало загрузку баннера.
     * Слот сохраняется, но исходник у него не учтён — сигнал остаётся.
     *
     * @test
     */
    public function the_upload_action_saves_a_slot_without_the_psd_link(): void
    {
        $course = Course::factory()->create(['is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->callTableAction('upload', $course, [
                'format' => '16:9',
                'image' => [UploadedFile::fake()->image('b.jpg', 1600, 900)],
                'psd_url' => '',
            ])
            ->assertHasNoTableActionErrors();

        $asset = CourseDesignAsset::where('course_id', $course->id)->where('format', '16:9')->first();

        $this->assertNotNull($asset, 'слот обязан появиться и без ссылки на исходник');
        $this->assertNull($asset->psd_url);
        $this->assertFalse($asset->hasPsd(), 'исходник не учтён — это и должно быть видно в интерфейсе');
        Storage::disk('public')->assertExists($asset->path);
    }

    /** Невалидный URL по-прежнему отклоняется — необязательное поле не значит «любая строка». */
    public function test_the_upload_action_still_rejects_a_malformed_psd_link(): void
    {
        $course = Course::factory()->create(['is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->callTableAction('upload', $course, [
                'format' => '16:9',
                'image' => [UploadedFile::fake()->image('b.jpg', 1600, 900)],
                'psd_url' => 'не-ссылка',
            ])
            ->assertHasTableActionErrors(['psd_url']);

        $this->assertDatabaseCount('course_design_assets', 0);
    }

    /**
     * Перенос обложки витрины через действие страницы: проводка модалки до
     * импортёра. Сама арифметика кадрирования закреплена CourseCoverImportTest.
     *
     * @test
     */
    public function the_import_action_fills_the_four_to_three_slot(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        Storage::disk('public')->put('courses/cover.jpg', (string) UploadedFile::fake()->image('cover.jpg', 1600, 900)->get());
        $course->update(['image_path' => 'courses/cover.jpg']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->callTableAction('importCover', $course)
            ->assertHasNoTableActionErrors();

        $asset = CourseDesignAsset::where('course_id', $course->id)->where('format', '4:3')->first();

        $this->assertNotNull($asset, 'слот 4:3 должен появиться после переноса');
        $this->assertSame([1200, 900], [$asset->width, $asset->height]);
        Storage::disk('public')->assertExists($asset->path);
        // Витрина не тронута.
        $this->assertSame('courses/cover.jpg', $course->fresh()->image_path);
        Storage::disk('public')->assertExists('courses/cover.jpg');
    }

    /**
     * Массовый перенос: считает перенесённые и пропущенные, занятый слот не
     * перезаписывает.
     *
     * @test
     */
    public function the_bulk_import_fills_only_the_empty_slots(): void
    {
        $withCover = Course::factory()->create(['is_active' => true]);
        Storage::disk('public')->put('courses/a.jpg', (string) UploadedFile::fake()->image('a.jpg', 1600, 900)->get());
        $withCover->update(['image_path' => 'courses/a.jpg']);

        $withoutCover = Course::factory()->create(['is_active' => true, 'image_path' => null]);

        $taken = Course::factory()->create(['is_active' => true]);
        Storage::disk('public')->put('courses/c.jpg', (string) UploadedFile::fake()->image('c.jpg', 1600, 900)->get());
        $taken->update(['image_path' => 'courses/c.jpg']);
        $handMade = app(CourseDesignAssetService::class)->store(
            $taken, '4:3', UploadedFile::fake()->image('hand.jpg', 1200, 900), null, null, $this->user(Roles::ADMIN),
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->callTableBulkAction('importCovers', [$withCover, $withoutCover, $taken])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNotNull(CourseDesignAsset::where('course_id', $withCover->id)->where('format', '4:3')->first());
        $this->assertSame(0, CourseDesignAsset::where('course_id', $withoutCover->id)->count());
        $this->assertSame($handMade->path, $handMade->fresh()->path, 'занятый слот перезаписывать нельзя');
    }

    /**
     * Колонка «Объём» и карточка «Занято на диске» должны реально отрисоваться:
     * сумма приходит из withSum-алиаса, а он ломается молча — колонка просто
     * покажет прочерк.
     *
     * @test
     */
    public function the_matrix_shows_the_uploaded_volume(): void
    {
        $course = Course::factory()->create(['title' => 'Курс с объёмом', 'is_active' => true]);

        $asset = app(CourseDesignAssetService::class)->store(
            $course,
            '16:9',
            UploadedFile::fake()->image('b.jpg', 1600, 900),
            'https://disk.example/psd',
            null,
            $this->user(Roles::ADMIN),
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::MANAGER));

        Livewire::test(CourseDesignAssets::class)
            ->assertOk()
            ->assertSee(CourseDesignAssetService::formatBytes((int) $asset->size));
    }

    /** @test */
    public function a_manager_can_download_the_psd_file(): void
    {
        $asset = $this->assetWithPsd();

        $this->actingAs($this->user(Roles::MANAGER))
            ->get(route('course-design.psd', $asset))
            ->assertOk();
    }

    /** @test */
    public function a_student_cannot_download_the_psd_file(): void
    {
        $asset = $this->assetWithPsd();

        $this->actingAs($this->user(null))
            ->get(route('course-design.psd', $asset))
            ->assertForbidden();
    }

    /** @test */
    public function a_guest_is_not_served_the_psd_file(): void
    {
        $asset = $this->assetWithPsd();

        $this->get(route('course-design.psd', $asset))->assertRedirect();
    }

    /** @test */
    public function a_slot_without_an_uploaded_psd_returns_404_not_an_error(): void
    {
        $course = Course::factory()->create();
        $asset = app(CourseDesignAssetService::class)->store(
            $course,
            '16:9',
            UploadedFile::fake()->image('b.jpg', 1600, 900),
            'https://disk.example/only-a-link',
            null,
            $this->user(Roles::ADMIN),
        );

        $this->actingAs($this->user(Roles::ADMIN))
            ->get(route('course-design.psd', $asset))
            ->assertNotFound();
    }

    /** @test */
    public function a_manager_can_pull_the_course_archive(): void
    {
        $asset = $this->assetWithPsd();

        $response = $this->actingAs($this->user(Roles::MANAGER))
            ->get(route('course-design.archive', $asset->course));

        $response->assertOk();
        $this->assertStringContainsString('.zip', (string) $response->headers->get('content-disposition'));
    }

    /** @test */
    public function a_course_without_banners_gives_404_not_a_broken_archive(): void
    {
        $course = Course::factory()->create();

        $this->actingAs($this->user(Roles::ADMIN))
            ->get(route('course-design.archive', $course))
            ->assertNotFound();
    }

    /** @test */
    public function a_student_cannot_pull_an_archive(): void
    {
        $asset = $this->assetWithPsd();

        $this->actingAs($this->user(null))
            ->get(route('course-design.archive', $asset->course))
            ->assertForbidden();
    }

    private function assetWithPsd(): CourseDesignAsset
    {
        return app(CourseDesignAssetService::class)->store(
            Course::factory()->create(),
            '16:9',
            UploadedFile::fake()->image('banner.jpg', 1600, 900),
            'https://disk.example/psd',
            UploadedFile::fake()->create('source.psd', 8),
            $this->user(Roles::ADMIN),
        );
    }
}
