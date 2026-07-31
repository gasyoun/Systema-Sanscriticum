<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSlugAlias;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShortCourseUrlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** @test */
    public function named_routes_generate_short_paths(): void
    {
        $this->assertStringContainsString('/c/hindi-2_sb1300-2026', route('student.course', 'hindi-2_sb1300-2026', false));
        $this->assertStringContainsString(
            '/c/hindi-2_sb1300-2026/u/1667',
            route('student.lesson', ['slug' => 'hindi-2_sb1300-2026', 'lessonId' => 1667], false)
        );
        $this->assertStringContainsString('/k/hindi-2_sb1300-2026', route('shop.course.show', 'hindi-2_sb1300-2026', false));
        $this->assertStringNotContainsString('/course/', route('student.lesson', ['slug' => 'x', 'lessonId' => 1], false));
        $this->assertStringNotContainsString('/lesson/', route('student.lesson', ['slug' => 'x', 'lessonId' => 1], false));
        $this->assertStringNotContainsString('/online/kursy/', route('shop.course.show', 'x', false));
    }

    /** @test */
    public function short_lesson_url_is_ok_for_free_lesson(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['slug' => 'hindi-2_sb1300-2026']);
        $lesson = Lesson::factory()->for($course)->free()->create();

        $this->actingAs($user)
            ->get('/c/'.$course->slug.'/u/'.$lesson->id)
            ->assertOk();
    }

    /** @test */
    public function legacy_lesson_path_redirects_301_to_short(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['slug' => 'hindi-2_sb1300-2026']);
        $lesson = Lesson::factory()->for($course)->free()->create();

        $this->actingAs($user)
            ->get('/course/'.$course->slug.'/lesson/'.$lesson->id)
            ->assertRedirect(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertStatus(301);
    }

    /** @test */
    public function legacy_shop_path_redirects_301_to_short(): void
    {
        $course = Course::factory()->create(['slug' => 'test-shop-course']);

        $this->get('/online/kursy/'.$course->slug)
            ->assertRedirect(route('shop.course.show', $course->slug))
            ->assertStatus(301);
    }

    /** @test */
    public function short_shop_url_returns_ok(): void
    {
        $course = Course::factory()->create(['slug' => 'test-shop-course']);

        $this->get('/k/'.$course->slug)->assertOk();
    }

    /** @test */
    public function alias_slug_on_short_path_redirects_to_canonical(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['slug' => 'hindi-2_sb1300-2026']);
        CourseSlugAlias::query()->create([
            'course_id' => $course->id,
            'slug' => 'grammatika-xindi-gr-2-subbota-1300-2026',
            'created_at' => now(),
        ]);
        $lesson = Lesson::factory()->for($course)->free()->create();

        $this->actingAs($user)
            ->get('/c/grammatika-xindi-gr-2-subbota-1300-2026/u/'.$lesson->id)
            ->assertRedirect(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertStatus(301);
    }

    /** @test */
    public function renaming_slug_creates_alias_for_301(): void
    {
        $course = Course::factory()->create(['slug' => 'grammatika-xindi-gr-2-subbota-1300-2026']);
        $course->slug = 'hindi-2_sb1300-2026';
        $course->save();

        $this->assertDatabaseHas('course_slug_aliases', [
            'course_id' => $course->id,
            'slug' => 'grammatika-xindi-gr-2-subbota-1300-2026',
        ]);
        $this->assertSame('hindi-2_sb1300-2026', $course->fresh()->slug);

        $this->get('/k/grammatika-xindi-gr-2-subbota-1300-2026')
            ->assertRedirect(route('shop.course.show', 'hindi-2_sb1300-2026'))
            ->assertStatus(301);
    }

    /** @test */
    public function student_course_page_uses_short_path_with_group_access(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['slug' => 'hindi-2_sb1300-2026', 'is_active' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk();
    }
}
