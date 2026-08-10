<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2527 — библиотека курса, фаза 1: реестр ссылок на материалы.
 *
 * Проверяем ровно контур доступа: флаг, членство в группе курса, видимость
 * строки и наследование замка урока. Тарифы страницу целиком НЕ гейтят —
 * иначе общая библиография исчезала бы между оплатами блоков.
 */
class CourseLibraryAccessTest extends TestCase
{
    use RefreshDatabase;

    private function enrolledStudent(Course $course): User
    {
        $group = Group::create(['name' => 'Библиотека тест']);
        $course->groups()->attach($group->id);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);

        return $user;
    }

    public function test_route_is_404_when_feature_flag_is_off(): void
    {
        config()->set('features.course_library', false);

        $course = Course::factory()->create(['is_active' => true]);
        $user = $this->enrolledStudent($course);

        $this->actingAs($user)
            ->get(route('student.course.library', $course->slug))
            ->assertNotFound();
    }

    public function test_student_without_course_group_gets_404(): void
    {
        config()->set('features.course_library', true);

        $course = Course::factory()->create(['is_active' => true]);
        $this->enrolledStudent($course); // группа есть, но у другого студента

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('student.course.library', $course->slug))
            ->assertNotFound();
    }

    public function test_enrolled_student_sees_visible_course_wide_material(): void
    {
        config()->set('features.course_library', true);

        $course = Course::factory()->create(['is_active' => true]);
        $user = $this->enrolledStudent($course);

        CourseMaterial::create([
            'course_id' => $course->id,
            'title' => 'Cardona 1997',
            'source_url' => 'https://www.dropbox.com/scl/fi/x/panini.pdf?rlkey=abc&st=zzz&dl=0',
            'is_visible' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('student.course.library', $course->slug))
            ->assertOk()
            ->assertSee('Cardona 1997');

        // Ссылка отдаётся студенту уже нормализованной, с сохранённым rlkey.
        $response->assertSee('dl=1', false);
        $response->assertSee('rlkey=abc', false);
    }

    public function test_invisible_material_is_hidden(): void
    {
        config()->set('features.course_library', true);

        $course = Course::factory()->create(['is_active' => true]);
        $user = $this->enrolledStudent($course);

        CourseMaterial::create([
            'course_id' => $course->id,
            'title' => 'Черновик полки',
            'source_url' => 'https://example.org/draft.pdf',
            'is_visible' => false,
        ]);

        $this->actingAs($user)
            ->get(route('student.course.library', $course->slug))
            ->assertOk()
            ->assertDontSee('Черновик полки');
    }

    public function test_material_of_free_lesson_is_visible_and_locked_lesson_is_not(): void
    {
        config()->set('features.course_library', true);

        $course = Course::factory()->create(['is_active' => true]);
        $user = $this->enrolledStudent($course);

        $freeLesson = Lesson::factory()->for($course)->create([
            'title' => 'Открытый урок',
            'is_free' => true,
            'is_published' => true,
            'sort_order' => 1,
        ]);

        $lockedLesson = Lesson::factory()->for($course)->create([
            'title' => 'Закрытый урок',
            'is_free' => false,
            'is_preview' => false,
            'is_published' => true,
            'sort_order' => 2,
        ]);

        CourseMaterial::create([
            'course_id' => $course->id,
            'lesson_id' => $freeLesson->id,
            'title' => 'Хрестоматия открытого урока',
            'source_url' => 'https://example.org/free.pdf',
            'is_visible' => true,
        ]);

        CourseMaterial::create([
            'course_id' => $course->id,
            'lesson_id' => $lockedLesson->id,
            'title' => 'Хрестоматия закрытого урока',
            'source_url' => 'https://example.org/locked.pdf',
            'is_visible' => true,
        ]);

        $this->actingAs($user)
            ->get(route('student.course.library', $course->slug))
            ->assertOk()
            ->assertSee('Хрестоматия открытого урока')
            ->assertDontSee('Хрестоматия закрытого урока');
    }

    public function test_inactive_course_gets_404(): void
    {
        config()->set('features.course_library', true);

        $course = Course::factory()->create(['is_active' => false]);
        $user = $this->enrolledStudent($course);

        $this->actingAs($user)
            ->get(route('student.course.library', $course->slug))
            ->assertNotFound();
    }

    public function test_model_derives_host_kind_and_download_url_on_create(): void
    {
        $course = Course::factory()->create();

        $material = CourseMaterial::create([
            'course_id' => $course->id,
            'source_url' => 'https://www.dropbox.com/scl/fi/x/Cardona_Panini.pdf?rlkey=abc&st=zzz&dl=0',
            'is_visible' => true,
        ]);

        $this->assertSame('dropbox.com', $material->host);
        $this->assertSame('pdf', $material->kind);
        $this->assertStringContainsString('dl=1', $material->download_url);
        // Название подставлено черновиком из имени файла.
        $this->assertStringContainsString('Cardona', $material->title);
        $this->assertSame($material->download_url, $material->effectiveUrl());
    }
}
