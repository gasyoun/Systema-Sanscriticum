<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    /** Студент с доступом к курсу через группу. */
    private function enrolledStudent(): array
    {
        $course = Course::factory()->create(['is_active' => true, 'slug' => 'sanskrit-101', 'title' => 'Санскрит 101']);
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $user->groups()->attach($group->id);

        return [$user, $course, $group];
    }

    /** @test */
    public function login_returns_a_token_and_user(): void
    {
        $user = User::factory()->create(['email' => 'a@example.test', 'password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@example.test',
            'password' => 'secret123',
            'device_name' => 'iPhone',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'prana_balance']])
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'iPhone']);
    }

    /** @test */
    public function login_rejects_wrong_password(): void
    {
        User::factory()->create(['email' => 'a@example.test', 'password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@example.test',
            'password' => 'WRONG',
        ])->assertStatus(422);
    }

    /** @test */
    public function protected_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/courses')->assertUnauthorized();
    }

    /** @test */
    public function courses_endpoint_returns_progress(): void
    {
        [$user, $course] = $this->enrolledStudent();
        $lessons = Lesson::factory()->count(4)->for($course)->create(['group_id' => null]);
        $user->completedLessons()->attach($lessons[0]->id, ['is_completed' => true]);
        $user->completedLessons()->attach($lessons[1]->id, ['is_completed' => true]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonPath('courses.0.slug', 'sanskrit-101')
            ->assertJsonPath('courses.0.lessons_total', 4)
            ->assertJsonPath('courses.0.lessons_completed', 2)
            ->assertJsonPath('courses.0.percent', 50);
    }

    /** @test */
    public function lessons_endpoint_marks_locked_and_completed(): void
    {
        [$user, $course] = $this->enrolledStudent();
        $free = Lesson::factory()->for($course)->create(['group_id' => null, 'is_free' => true, 'title' => 'Бесплатный']);
        $paid = Lesson::factory()->for($course)->create(['group_id' => null, 'is_free' => false, 'block_number' => 1, 'title' => 'Платный']);

        $user->completedLessons()->attach($free->id, ['is_completed' => true]);

        Sanctum::actingAs($user);

        $resp = $this->getJson('/api/v1/courses/sanskrit-101/lessons')->assertOk();
        $resp->assertJsonPath('course.slug', 'sanskrit-101');

        $lessons = collect($resp->json('lessons'))->keyBy('id');
        $this->assertFalse($lessons[$free->id]['locked']);
        $this->assertTrue($lessons[$free->id]['is_completed']);
        $this->assertTrue($lessons[$paid->id]['locked']);

        // Оплатил блок 1 — платный урок открывается.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 1000,
            'tariff' => 'block_1', 'status' => 'paid', 'start_block' => 1, 'end_block' => 1,
        ]);

        $after = collect($this->getJson('/api/v1/courses/sanskrit-101/lessons')->json('lessons'))->keyBy('id');
        $this->assertFalse($after[$paid->id]['locked']);
    }
}
