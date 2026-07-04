<?php

namespace Tests\Feature;

use App\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Filament\Resources\LessonResource\Pages\CreateLesson;
use App\Filament\Resources\TestimonialResource\Pages\CreateTestimonial;
use App\Filament\Resources\TestimonialResource\Pages\ListTestimonials;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke-тесты Filament для лендинга (H154, фаза 4): формы/страницы строятся без
 * рантайм-ошибок, а валидация «не более одного preview-урока на курс» работает.
 */
class CourseLandingFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    public function test_course_create_form_with_landing_section_builds(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateCourse::class)
            ->assertOk()
            ->assertFormExists();
    }

    public function test_testimonial_resource_pages_build(): void
    {
        Livewire::actingAs($this->admin())->test(ListTestimonials::class)->assertOk();
        Livewire::actingAs($this->admin())->test(CreateTestimonial::class)->assertOk()->assertFormExists();
    }

    public function test_second_preview_lesson_on_same_course_is_rejected(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->create([
            'course_id' => $course->id,
            'is_preview' => true,
            'is_published' => true,
        ]);

        Livewire::actingAs($this->admin())
            ->test(CreateLesson::class)
            ->fillForm([
                'course_id' => $course->id,
                'title' => 'Второй пробный',
                'block_number' => 1,
                'is_preview' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['is_preview']);

        // Второй preview не создан.
        $this->assertSame(1, Lesson::where('course_id', $course->id)->where('is_preview', true)->count());
    }

    public function test_first_preview_lesson_is_accepted(): void
    {
        $course = Course::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CreateLesson::class)
            ->fillForm([
                'course_id' => $course->id,
                'title' => 'Первый пробный',
                'block_number' => 1,
                'is_preview' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Lesson::where('course_id', $course->id)->where('is_preview', true)->count());
    }
}
