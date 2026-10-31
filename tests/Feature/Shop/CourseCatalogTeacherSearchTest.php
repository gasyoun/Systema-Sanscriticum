<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** H7589: free-text поиск каталога матчит и преподавателя (title OR teachers.name). */
class CourseCatalogTeacherSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function search_by_teacher_surname_finds_their_courses(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Леонов Максим']);
        /** @var Course $course */
        $course = Course::factory()->create(['title' => 'Ранние санскритские рукописи']);
        $course->teachers()->attach($teacher);

        Livewire::test(CourseCatalog::class)
            ->set('search', 'Леонов')
            ->assertSee('Ранние санскритские рукописи');
    }

    /** @test */
    public function search_still_matches_plain_title_and_ignores_unknown_names(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Леонов Максим']);
        /** @var Course $course */
        $course = Course::factory()->create(['title' => 'Ранние санскритские рукописи']);
        $course->teachers()->attach($teacher);
        Livewire::test(CourseCatalog::class)
            ->set('search', 'Ранние')
            ->assertSee('Ранние санскритские рукописи')
            ->set('search', 'Гагарин')
            ->assertDontSee('Ранние санскритские рукописи');
    }
    /** @test */
    public function search_matches_a_course_whose_main_teacher_fk_matches(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Леонов Максим Владимирович']);
        $course = Course::factory()->create([
            'title' => 'Ведийская литература (2026)',
            'teacher_id' => $teacher->id,
        ]);
        $this->assertSame(0, DB::table('course_teacher')->count());

        Livewire::test(CourseCatalog::class)
            ->set('search', 'Леонов')
            ->assertSee('Ведийская литература (2026)');
    }
}
