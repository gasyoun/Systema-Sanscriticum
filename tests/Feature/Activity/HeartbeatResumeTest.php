<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * In-video resume (H1450, Anton ops-gaps W2). position/duration едут поверх
 * СУЩЕСТВУЮЩЕГО POST /api/heartbeat — никакого нового эндпоинта.
 *
 * H3315: heartbeat пишет watch-time только entitled-зрителю (гейт плеера,
 * LessonGate), поэтому уроки здесь открытые (free), а Redis-троттлинг
 * подменяется фасадом (в тестовой среде Redis недоступен).
 */
class HeartbeatResumeTest extends TestCase
{
    use RefreshDatabase;

    /** Троттлинг-Redis отвечает «слот захвачен» без реального сервера. */
    private function allowThrottle(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
    }

    private function lessonFor(User $user): Lesson
    {
        $course = Course::factory()->create();

        // free: любой залогиненный может смотреть — гейт H3315 пропускает.
        return Lesson::factory()->free()->for($course)->create();
    }

    /** @test */
    public function first_heartbeat_with_position_persists_last_and_max_position(): void
    {
        $this->allowThrottle();
        $user = User::factory()->create();
        $lesson = $this->lessonFor($user);

        $this->actingAs($user)->postJson(route('activity.heartbeat'), [
            'lesson_id' => $lesson->id,
            'delta_seconds' => 30,
            'position' => 120,
            'duration' => 1800,
        ])->assertOk()->assertJson(['ok' => true]);

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        $this->assertNotNull($view);
        $this->assertSame(120, $view->last_position_seconds);
        $this->assertSame(120, $view->max_position_seconds);
        $this->assertSame(1800, $view->video_duration_seconds);
        $this->assertSame(30, $view->total_time_on_page);
    }

    /** @test */
    public function max_position_seconds_never_regresses_below_a_previously_stored_value(): void
    {
        $this->allowThrottle();
        $user = User::factory()->create();
        $lesson = $this->lessonFor($user);

        // Студент уже дошёл до 9-й минуты в прошлой сессии.
        LessonView::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'first_opened_at' => now()->subDay(),
            'last_opened_at' => now()->subDay(),
            'last_heartbeat_at' => now()->subDay(),
            'open_count' => 1,
            'total_time_on_page' => 500,
            'is_completed' => false,
            'last_position_seconds' => 540,
            'max_position_seconds' => 540,
            'video_duration_seconds' => 1800,
        ]);

        // Новая сессия: отмотал назад к 2-й минуте (перематывание — обычное дело).
        $this->actingAs($user)->postJson(route('activity.heartbeat'), [
            'lesson_id' => $lesson->id,
            'delta_seconds' => 20,
            'position' => 120,
        ])->assertOk();

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        // last_position следует за плеером (реально там, где он сейчас), но
        // max_position — прогресс-сигнал, который отмотка назад не портит.
        $this->assertSame(120, $view->last_position_seconds);
        $this->assertSame(540, $view->max_position_seconds);
    }

    /** @test */
    public function heartbeat_without_position_behaves_exactly_as_before(): void
    {
        $this->allowThrottle();
        $user = User::factory()->create();
        $lesson = $this->lessonFor($user);

        $this->actingAs($user)->postJson(route('activity.heartbeat'), [
            'lesson_id' => $lesson->id,
            'delta_seconds' => 25,
        ])->assertOk()->assertJson(['ok' => true]);

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        $this->assertNotNull($view);
        $this->assertSame(25, $view->total_time_on_page);
        $this->assertNull($view->last_position_seconds);
        $this->assertNull($view->max_position_seconds);
        $this->assertNull($view->video_duration_seconds);
    }

    /** @test */
    public function migration_is_additive_and_existing_lesson_view_columns_are_untouched(): void
    {
        $this->assertTrue(Schema::hasColumns('lesson_views', [
            'open_count',
            'total_time_on_page',
            'is_completed',
            'last_position_seconds',
            'max_position_seconds',
            'video_duration_seconds',
        ]));
    }
}
