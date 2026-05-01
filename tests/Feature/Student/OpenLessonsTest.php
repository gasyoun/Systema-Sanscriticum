<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenLessonsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/open-lessons')
            ->assertRedirect('/login');
    }

    /** @test */
    public function authenticated_user_gets_ok(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/open-lessons')
            ->assertOk();
    }

    /** @test */
    public function only_free_and_published_lessons_are_listed(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $visible    = Lesson::factory()->for($course)->free()->create(['title' => 'Открытый и опубликован']);
        $unpublished = Lesson::factory()->for($course)->free()->unpublished()->create(['title' => 'Открытый но скрытый']);
        $paid       = Lesson::factory()->for($course)->create(['title' => 'Платный']);

        $html = $this->actingAs($user)->get('/open-lessons')->getContent();

        $this->assertStringContainsString('Открытый и опубликован', $html);
        $this->assertStringNotContainsString('Открытый но скрытый', $html);
        $this->assertStringNotContainsString('Платный', $html);
    }

    /** @test */
    public function empty_state_shown_when_no_free_lessons_exist(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/open-lessons')->getContent();

        $this->assertStringContainsString('Пока нет открытых уроков', $html);
    }
}
