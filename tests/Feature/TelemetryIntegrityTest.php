<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GameEvent;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\LessonView;
use App\Models\LilaScoreEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

/**
 * H3315 — целостность телеметрии (fresh-eyes audit 22-08-2026):
 *
 *  1) heartbeat пишет watch-time ТОЛЬКО entitled-зрителю — тот же гейт, что
 *     у плеера (LessonGate); не прошедшим 404, ноль строк lesson_views.
 *  2) Отказ Redis троттлинга больше не fail-open: запись консервативно
 *     пропускается, клиент получает ok/throttled — никогда не 500.
 *  3) Очки /lila серверные: payload.score клиента игнорируется, пишется
 *     значение таблицы EVENT_POINTS (complete = 10).
 */
class TelemetryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const HEARTBEAT_URL = '/api/heartbeat';

    private const GAME_EVENT_URL = '/api/games/event';

    /** Троттлинг-Redis отвечает «слот захвачен» без реального сервера. */
    private function allowThrottle(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
    }

    /** @test */
    public function non_entitled_user_heartbeat_is_404_and_writes_zero_watch_time(): void
    {
        // Платный урок без гранта/оплаты/группы — B не может раздувать
        // watch-time по уроку, который ему не открыт.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create(); // is_free=false

        $this->allowThrottle();

        $this->actingAs($user)
            ->postJson(self::HEARTBEAT_URL, [
                'lesson_id' => $lesson->id,
                'delta_seconds' => 30,
            ])
            ->assertStatus(404);

        $this->assertDatabaseCount('lesson_views', 0);
    }

    /** @test */
    public function entitled_user_by_grant_gets_watch_time_row_written(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();

        LessonAccessGrant::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
        ]);

        $this->allowThrottle();

        $this->actingAs($user)
            ->postJson(self::HEARTBEAT_URL, [
                'lesson_id' => $lesson->id,
                'delta_seconds' => 30,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($view);
        $this->assertSame(30, $view->total_time_on_page);
    }

    /** @test */
    public function free_lesson_heartbeat_still_accrues_for_any_logged_in_user(): void
    {
        // Легитимный сценарий «открытый урок» не сломан гейтом.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->free()->for($course)->create();

        $this->allowThrottle();

        $this->actingAs($user)
            ->postJson(self::HEARTBEAT_URL, [
                'lesson_id' => $lesson->id,
                'delta_seconds' => 25,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($view);
        $this->assertSame(25, $view->total_time_on_page);
    }

    /** @test */
    public function redis_outage_skips_the_write_but_never_errors_the_response(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->free()->for($course)->create();

        // Redis недоступен → консервативный skip-write, ответ как throttled.
        Redis::shouldReceive('set')->andThrow(new RuntimeException('redis down'));

        $this->actingAs($user)
            ->postJson(self::HEARTBEAT_URL, [
                'lesson_id' => $lesson->id,
                'delta_seconds' => 30,
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'throttled' => true]);

        $this->assertDatabaseCount('lesson_views', 0);
    }

    /** @test */
    public function forged_client_score_yields_exactly_the_server_table_value(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->postJson(self::GAME_EVENT_URL, [
                'anon_id' => 'forger1',
                'drill' => 'cloze',
                'band' => 'b1',
                'event' => 'complete',
                'payload' => ['score' => 999999],
            ])
            ->assertNoContent();

        // Таблица EVENT_POINTS[complete] = 10; клиентский score отброшен.
        $row = LilaScoreEvent::where('user_id', $user->id)->where('drill', 'cloze')->first();
        $this->assertNotNull($row);
        $this->assertSame(10, $row->points);
    }

    /** @test */
    public function negative_client_score_cannot_lower_or_shift_points_either(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->postJson(self::GAME_EVENT_URL, [
                'drill' => 'sort',
                'event' => 'complete',
                'payload' => ['score' => -50],
            ])
            ->assertNoContent();

        $this->assertSame(10, LilaScoreEvent::where('user_id', $user->id)->first()->points);
    }

    /** @test */
    public function non_complete_events_never_write_leaderboard_rows_even_with_score_payload(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->postJson(self::GAME_EVENT_URL, [
                'drill' => 'match',
                'event' => 'start',
                'payload' => ['score' => 999999],
            ])
            ->assertNoContent();

        $this->assertDatabaseCount('lila_score_events', 0);
        $this->assertSame(1, GameEvent::count()); // сырое событие записано как обычно
    }

    /** @test */
    public function anonymous_complete_still_writes_no_leaderboard_rows(): void
    {
        $this->postJson(self::GAME_EVENT_URL, [
            'anon_id' => 'guest9',
            'drill' => 'table',
            'event' => 'complete',
            'payload' => ['score' => 999999],
        ])->assertNoContent();

        $this->assertDatabaseCount('lila_score_events', 0);
    }
}
