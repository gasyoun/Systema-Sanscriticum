<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\WebinarAttendance;
use App\Support\InsertOnlyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * H3761, волна 3 — `attendance:backfill-streams`.
 *
 * Проверяется ровно то, чем эта команда отличается от H3085-й: только вставки,
 * занятия задним числом не заводятся, слепой период называется вслух.
 */
class BackfillStreamAttendanceTest extends TestCase
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

    private function fakeZoom(array $meetings, array $participants = []): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/*/instances' => Http::response(['meetings' => $meetings]),
            'api.zoom.us/v2/report/meetings/*/participants*' => Http::response([
                'participants' => $participants,
                'next_page_token' => '',
            ]),
        ]);
    }

    private function courseWithLesson(string $date): array
    {
        $course = Course::factory()->create(['zoom_meeting_id' => '999888777']);
        $schedule = Schedule::create([
            'title' => 'Занятие', 'course_id' => $course->id,
            'link' => 'https://us02web.zoom.us/j/999888777?pwd=abc',
            'start' => $date.' 14:00:00', 'end' => $date.' 16:00:00',
        ]);

        return [$course, $schedule];
    }

    /** @test */
    public function requires_since_option(): void
    {
        Course::factory()->create(['zoom_meeting_id' => '999888777']);

        $this->artisan('attendance:backfill-streams')->assertFailed();
    }

    /** @test */
    public function dry_run_writes_nothing(): void
    {
        [$course] = $this->courseWithLesson('2026-07-01');

        $this->fakeZoom(
            [['uuid' => 'occ-1==', 'start_time' => '2026-07-01T10:21:00Z']],
            [
                ['participant_uuid' => 'p1', 'name' => 'A', 'join_time' => '2026-07-01T10:22:00Z', 'duration' => 3600],
                ['participant_uuid' => 'p2', 'name' => 'B', 'join_time' => '2026-07-01T10:23:00Z', 'duration' => 3600],
            ],
        );

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01")
            ->assertSuccessful();

        $this->assertSame(0, WebinarAttendance::count());
    }

    /** @test */
    public function apply_inserts_attendance_for_an_existing_lesson(): void
    {
        [$course, $schedule] = $this->courseWithLesson('2026-07-01');

        $this->fakeZoom(
            [['uuid' => 'occ-1==', 'start_time' => '2026-07-01T10:21:00Z']],
            [
                ['participant_uuid' => 'p1', 'name' => 'A', 'join_time' => '2026-07-01T10:22:00Z', 'duration' => 3600],
                ['participant_uuid' => 'p2', 'name' => 'B', 'join_time' => '2026-07-01T10:23:00Z', 'duration' => 1800],
            ],
        );

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01 --apply")
            ->assertSuccessful();

        $this->assertSame(2, WebinarAttendance::where('schedule_id', $schedule->id)->count());
    }

    /** @test */
    public function second_run_is_a_no_op_and_never_updates_existing_rows(): void
    {
        [$course, $schedule] = $this->courseWithLesson('2026-07-01');

        $this->fakeZoom(
            [['uuid' => 'occ-1==', 'start_time' => '2026-07-01T10:21:00Z']],
            [
                ['participant_uuid' => 'p1', 'name' => 'A', 'join_time' => '2026-07-01T10:22:00Z', 'duration' => 3600],
                ['participant_uuid' => 'p2', 'name' => 'B', 'join_time' => '2026-07-01T10:23:00Z', 'duration' => 1800],
            ],
        );

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01 --apply")
            ->assertSuccessful();

        $row = WebinarAttendance::where('schedule_id', $schedule->id)->orderBy('id')->first();
        $stamp = $row->updated_at->toDateTimeString();

        // Второй прогон: тот же Zoom, но теперь Reports API отдаёт другую
        // длительность — команда обязана НЕ переписать уже собранное.
        $this->fakeZoom(
            [['uuid' => 'occ-1==', 'start_time' => '2026-07-01T10:21:00Z']],
            [
                ['participant_uuid' => 'p1', 'name' => 'A-изменено', 'join_time' => '2026-07-01T10:22:00Z', 'duration' => 99],
                ['participant_uuid' => 'p2', 'name' => 'B', 'join_time' => '2026-07-01T10:23:00Z', 'duration' => 1800],
            ],
        );

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01 --apply")
            ->assertSuccessful();

        $this->assertSame(2, WebinarAttendance::where('schedule_id', $schedule->id)->count());
        $fresh = $row->fresh();
        $this->assertSame('A', $fresh->name);
        $this->assertSame(3600, $fresh->duration_seconds);
        $this->assertSame($stamp, $fresh->updated_at->toDateTimeString());
    }

    /** @test */
    public function never_creates_a_lesson_for_a_zoom_run_with_no_schedule(): void
    {
        [$course] = $this->courseWithLesson('2026-07-01');

        $this->fakeZoom(
            [
                ['uuid' => 'occ-known==', 'start_time' => '2026-07-01T10:21:00Z'],
                ['uuid' => 'occ-orphan==', 'start_time' => '2026-06-24T10:21:00Z'],
            ],
            [
                ['participant_uuid' => 'p1', 'name' => 'A', 'join_time' => '2026-07-01T10:22:00Z', 'duration' => 3600],
                ['participant_uuid' => 'p2', 'name' => 'B', 'join_time' => '2026-07-01T10:23:00Z', 'duration' => 1800],
            ],
        );

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01 --apply")
            ->assertSuccessful();

        $this->assertSame(1, Schedule::where('course_id', $course->id)->count(), 'занятие задним числом не заводится');
        $this->assertSame(0, WebinarAttendance::whereNull('schedule_id')->count());
    }

    /** @test */
    public function a_host_only_run_is_not_counted_as_a_lesson(): void
    {
        [$course, $schedule] = $this->courseWithLesson('2026-07-01');

        $this->fakeZoom(
            [['uuid' => 'occ-1==', 'start_time' => '2026-07-01T10:21:00Z']],
            [['participant_uuid' => 'host', 'name' => 'Ведущий', 'join_time' => '2026-07-01T10:22:00Z', 'duration' => 60]],
        );

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01 --min-participants=2 --apply")
            ->assertSuccessful();

        $this->assertSame(0, WebinarAttendance::where('schedule_id', $schedule->id)->count());
    }

    /** @test */
    public function a_lesson_with_no_zoom_source_stays_blind_and_is_reported(): void
    {
        [$course] = $this->courseWithLesson('2026-07-01');

        $this->fakeZoom([]); // Zoom ничего не сохранил

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01 --apply")
            ->expectsOutputToContain('Слепые занятия')
            ->assertSuccessful();

        $this->assertSame(0, WebinarAttendance::count());
    }

    /**
     * Курс без своего meeting_id и без zoom_link — id берётся со ссылки его
     * занятия. Именно эта цепочка проверялась в волне 3: `Course` выводит
     * `zoom_meeting_id` из `zoom_link` мутатором, поэтому «есть ссылка, но нет
     * id» на модели недостижимо, а «нет ни того, ни другого» — реальный случай
     * курса 332.
     *
     * @test
     */
    public function meeting_id_resolves_from_the_lesson_link_when_the_course_has_none(): void
    {
        $course = Course::factory()->create([
            'zoom_meeting_id' => null,
            'zoom_link' => null,
        ]);
        $schedule = Schedule::create([
            'title' => 'Занятие', 'course_id' => $course->id,
            'link' => 'https://us02web.zoom.us/j/999888777?pwd=abc',
            'start' => '2026-07-01 14:00:00', 'end' => '2026-07-01 16:00:00',
        ]);

        $this->fakeZoom(
            [['uuid' => 'occ-1==', 'start_time' => '2026-07-01T10:21:00Z']],
            [
                ['participant_uuid' => 'p1', 'name' => 'A', 'join_time' => '2026-07-01T10:22:00Z', 'duration' => 3600],
                ['participant_uuid' => 'p2', 'name' => 'B', 'join_time' => '2026-07-01T10:23:00Z', 'duration' => 1800],
            ],
        );

        $this->artisan("attendance:backfill-streams --course={$course->id} --since=2026-06-01 --apply")
            ->assertSuccessful();

        $this->assertSame(2, WebinarAttendance::where('schedule_id', $schedule->id)->count());
        $this->assertNull($course->fresh()->zoom_meeting_id, 'курс не переключается на живой sync автоматически');
    }

    /** @test */
    public function insert_only_guard_aborts_a_forbidden_write(): void
    {
        [, $schedule] = $this->courseWithLesson('2026-07-01');
        WebinarAttendance::create([
            'schedule_id' => $schedule->id, 'zoom_participant_uuid' => 'p1', 'name' => 'A',
        ]);

        $this->expectException(RuntimeException::class);

        InsertOnlyGuard::around(function () use ($schedule): void {
            WebinarAttendance::where('schedule_id', $schedule->id)->update(['name' => 'переписано']);
        });
    }

    /** @test */
    public function insert_only_guard_allows_selects_and_inserts(): void
    {
        [, $schedule] = $this->courseWithLesson('2026-07-01');

        InsertOnlyGuard::around(function () use ($schedule): void {
            WebinarAttendance::where('schedule_id', $schedule->id)->count();
            WebinarAttendance::create([
                'schedule_id' => $schedule->id, 'zoom_participant_uuid' => 'p9', 'name' => 'Новый',
            ]);
        });

        $this->assertSame(1, WebinarAttendance::where('schedule_id', $schedule->id)->count());
    }
}
