<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
use App\Models\Schedule;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * H8495 — еженедельная сверка «расписание ↔ Zoom» (read-only гард).
 *
 * Проверяется: чистая неделя печатает literal «0 findings»; мёртвая встреча
 * (GET 404) названа; занятие без всякого Zoom-источника названо missing;
 * занятие с чужим id названо mismatch; негативная канарейка ловится без
 * единой записи в БД; ни один прогон не пишет.
 */
class VerifyScheduleZoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.zoom.account_id' => 'acc',
            'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'secret',
        ]);
    }

    /**
     * @param  list<string>  $deadIds  meeting id, которые GET отвечает 404
     */
    private function fakeZoomGet(array $deadIds = []): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            // Без объявления возвращаемого типа: стаб вызывается в промис-контексте
            // (GuzzleHttp\Promise\FulfilledPromise), и явный return type роняет его TypeError'ом.
            'api.zoom.us/v2/meetings/*' => function (Request $request) use ($deadIds) {
                foreach ($deadIds as $dead) {
                    if (str_contains($request->url(), '/meetings/'.$dead)) {
                        return Http::response(['message' => 'Not found'], 404);
                    }
                }

                return Http::response(['topic' => 'Курс', 'type' => 8]);
            },
        ]);
    }

    private function lessonOnCourse(array $courseAttrs = [], array $lessonAttrs = []): array
    {
        $course = Course::factory()->create($courseAttrs);
        $schedule = Schedule::create(array_merge([
            'title' => 'Занятие', 'course_id' => $course->id,
            'start' => now()->addDays(2)->format('Y-m-d').' 14:00:00',
            'end' => now()->addDays(2)->format('Y-m-d').' 16:00:00',
        ], $lessonAttrs));

        return [$course, $schedule];
    }

    /** @test */
    public function a_clean_week_prints_zero_findings_and_notifies(): void
    {
        $this->fakeZoomGet();
        $this->lessonOnCourse(['zoom_meeting_id' => '999888777']);

        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('notifyAdmins')->once()->andReturnUsing(function (string $text): array {
                $this->assertStringContainsString('0 findings', $text);

                return ['chat-1'];
            });
        });

        $this->artisan('zoom:verify-schedule --notify')
            ->expectsOutputToContain('0 findings')
            ->assertSuccessful();
    }

    /** @test */
    public function a_dead_meeting_is_caught_and_named(): void
    {
        $this->fakeZoomGet(['999888777']);
        [, $schedule] = $this->lessonOnCourse(['zoom_meeting_id' => '999888777']);

        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock) use ($schedule): void {
            $mock->shouldReceive('notifyAdmins')->once()->andReturnUsing(function (string $text) use ($schedule): array {
                $this->assertStringContainsString('dead=1', $text);
                $this->assertStringContainsString('schedule#'.$schedule->id, $text);

                return ['chat-1'];
            });
        });

        $this->artisan('zoom:verify-schedule --notify')
            ->expectsOutputToContain('dead=1')
            ->assertSuccessful();
    }

    /** @test */
    public function a_lesson_without_any_zoom_source_is_named_missing(): void
    {
        $this->fakeZoomGet();
        $this->lessonOnCourse(['zoom_meeting_id' => null, 'zoom_link' => null]);

        $this->artisan('zoom:verify-schedule --notify')
            ->expectsOutputToContain('missing=1')
            ->assertSuccessful();
    }

    /** @test */
    public function a_lesson_pointing_at_another_room_is_named_mismatch(): void
    {
        $this->fakeZoomGet();
        // zoom_link мутатором выводит courses.zoom_meeting_id=888999000,
        // занятие при этом привязано к чужому 111222333.
        $this->lessonOnCourse(
            ['zoom_link' => 'https://us02web.zoom.us/j/888999000?pwd=abc'],
            ['zoom_meeting_id' => '111222333']
        );

        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('notifyAdmins')->once()->andReturnUsing(function (string $text): array {
                $this->assertStringContainsString('mismatch=1', $text);

                return ['chat-1'];
            });
        });

        $this->artisan('zoom:verify-schedule --notify')
            ->expectsOutputToContain('mismatch=1')
            ->assertSuccessful();
    }

    /**
     * Негативная канарейка: заведомо мёртвый синтетический id ловится
     * (класс «false 0 findings на известном сломанном fixture» исключён),
     * при этом ни одна строка БД не пишется и не правится.
     *
     * @test
     */
    public function the_canary_is_caught_without_touching_the_database(): void
    {
        $this->fakeZoomGet();
        $this->lessonOnCourse(['zoom_meeting_id' => '999888777']);

        $before = Schedule::count();

        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('notifyAdmins')->once()->andReturnUsing(function (string $text): array {
                $this->assertStringContainsString('канарейка', $text);
                $this->assertStringContainsString('dead=1', $text);

                return ['chat-1'];
            });
        });

        $this->artisan('zoom:verify-schedule --notify --canary')
            ->expectsOutputToContain('канарейка')
            ->assertSuccessful();

        $this->assertSame($before, Schedule::count(), 'canary must not write anything');
    }

    /** @test */
    public function the_weekly_entry_is_registered_before_the_reminder_week(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $event = null;
        foreach ($schedule->events() as $e) {
            if (str_contains((string) $e->command, 'zoom:verify-schedule')) {
                $event = $e;
            }
        }

        $this->assertNotNull($event, 'zoom:verify-schedule not in Kernel schedule');
        // weeklyOn(0, '06:00') → «0 6 * * 0» — воскресенье 06:00, до недели напоминаний.
        $this->assertSame('0 6 * * 0', $event->expression);
        $this->assertStringContainsString('--notify', $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
