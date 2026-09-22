<?php

declare(strict_types=1);

namespace Tests\Feature\Banners;

use App\Filament\Pages\LessonBanners;
use App\Models\Course;
use App\Models\LessonBannerTemplate;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Banners\LessonBannerTemplateStore;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Плашки занятий»: доступ, заведение шаблона (версии, проверка spec), превью.
 */
class LessonBannerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        config(['lesson_banners.fonts_dir' => storage_path('framework/testing/no-fonts-here')]);
    }

    public function test_only_admin_and_manager_see_the_page(): void
    {
        foreach ([Roles::ADMIN, Roles::MANAGER, Roles::SUPER_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->assertTrue(LessonBanners::canAccess(), $role.' должен видеть страницу');
        }

        foreach ([Roles::TEACHER, Roles::ACCOUNTANT, null] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->assertFalse(LessonBanners::canAccess(), ($role ?? 'студент').' не должен видеть страницу');
        }
    }

    public function test_new_template_supersedes_previous_version_for_same_course(): void
    {
        $course = $this->course();
        $store = app(LessonBannerTemplateStore::class);

        $v1 = $store->store($course->id, null, $this->png(), $this->spec());
        $v2 = $store->store($course->id, null, $this->png(), $this->spec());

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertFalse($v1->fresh()->is_active);
        $this->assertTrue($v2->fresh()->is_active);
        $this->assertSame([800, 450], [$v2->width, $v2->height]);
        Storage::disk('public')->assertExists($v2->background_path);
        $this->assertSame($v2->id, LessonBannerTemplate::resolveFor($course->id, null)?->id);
    }

    public function test_spec_with_field_outside_background_is_rejected(): void
    {
        $spec = json_decode($this->spec(), true);
        $spec['fields']['date']['x'] = 700;

        $this->expectException(InvalidArgumentException::class);
        app(LessonBannerTemplateStore::class)->store($this->course()->id, null, $this->png(), (string) json_encode($spec));
    }

    public function test_spec_number_format_must_contain_placeholder(): void
    {
        $spec = json_decode($this->spec(), true);
        $spec['fields']['number']['format'] = 'Занятие';

        $this->expectException(InvalidArgumentException::class);
        app(LessonBannerTemplateStore::class)->store($this->course()->id, null, $this->png(), (string) json_encode($spec));
    }

    public function test_page_lists_template_and_renders_preview(): void
    {
        $course = $this->course();
        $template = app(LessonBannerTemplateStore::class)->store($course->id, null, $this->png(), $this->spec());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));

        Livewire::test(LessonBanners::class)
            ->assertOk()
            ->assertSee('Грамматика')
            ->assertSee('v1')
            ->call('preview', $template->id)
            ->assertSet('previewDataUri', fn (?string $uri): bool => is_string($uri) && str_starts_with($uri, 'data:image/jpeg;base64,'));
    }

    private function course(): Course
    {
        $teacher = Teacher::create(['name' => 'Препод']);

        return Course::create(['title' => 'Грамматика', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
    }

    private function png(): UploadedFile
    {
        $image = imagecreatetruecolor(800, 450);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 30, 90));
        $path = tempnam(sys_get_temp_dir(), 'bg').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'background.png', 'image/png', null, true);
    }

    private function spec(): string
    {
        $field = ['w' => 360, 'h' => 80, 'align' => 'left', 'font' => 'Missing.ttf', 'size_px' => 48, 'color' => '#FFFFFF'];

        return (string) json_encode(['fields' => [
            'date' => $field + ['x' => 40, 'y' => 300, 'format' => 'D MMMM'],
            'number' => $field + ['x' => 40, 'y' => 60, 'format' => 'Занятие {N}'],
        ]], JSON_UNESCAPED_UNICODE);
    }
}
