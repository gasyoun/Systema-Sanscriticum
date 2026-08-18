<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\WebinarAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3085 — zoom:backfill-attendance: курс без courses.zoom_meeting_id (личная
 * recurring-комната), Schedule заведён лишь на часть занятий. Команда берёт
 * meeting_id со ссылки любого существующего Schedule, находит недостающие
 * даты по Zoom Reports API и бэкфилит и Schedule, и webinar_attendances.
 */
class BackfillZoomAttendanceTest extends TestCase
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

    /** @test */
    public function requires_since_option(): void
    {
        $course = Course::factory()->create(['zoom_meeting_id' => null]);

        $this->artisan("zoom:backfill-attendance {$course->id}")->assertFailed();
    }

    /** @test */
    public function dry_run_reports_plan_without_writing(): void
    {
        $course = Course::factory()->create(['zoom_meeting_id' => null]);
        Schedule::create([
            'title' => 'Занятие #16', 'course_id' => $course->id,
            'link' => 'https://us02web.zoom.us/j/999888777?pwd=abc',
            'start' => '2026-07-01 14:00:00', 'end' => '2026-07-01 16:00:00',
        ]);

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/999888777/instances' => Http::response([
                'meetings' => [
                    ['uuid' => 'occ-old==', 'start_time' => '2026-06-24T11:00:00Z'],
                    ['uuid' => 'occ-new==', 'start_time' => '2026-07-01T10:21:00Z'],
                ],
            ]),
        ]);

        $this->artisan("zoom:backfill-attendance {$course->id} --since=2026-06-01")
            ->expectsTable(
                ['Дата', 'Zoom-запусков', 'Schedule', 'Действие'],
                [
                    ['2026-06-24', 1, '—', 'завести новый'],
                    ['2026-07-01', 1, '1', 'привязать к #1'],
                ]
            )
            ->assertSuccessful();

        $this->assertSame(0, WebinarAttendance::count());
        $this->assertSame(1, Schedule::count());
    }

    /** @test */
    public function apply_creates_missing_schedule_and_records_attendance(): void
    {
        $course = Course::factory()->create(['zoom_meeting_id' => null]);
        $known = Schedule::create([
            'title' => 'Занятие #16', 'course_id' => $course->id,
            'link' => 'https://us02web.zoom.us/j/999888777?pwd=abc',
            'start' => '2026-07-01 14:00:00', 'end' => '2026-07-01 16:00:00',
        ]);

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/999888777/instances' => Http::response([
                'meetings' => [
                    ['uuid' => 'occ-old==', 'start_time' => '2026-06-24T11:00:00Z'],
                    ['uuid' => 'occ-new==', 'start_time' => '2026-07-01T10:21:00Z'],
                ],
            ]),
            'api.zoom.us/v2/report/meetings/*/participants*' => Http::response([
                'participants' => [
                    ['participant_uuid' => 'p1', 'name' => 'Студент', 'user_email' => '',
                        'join_time' => '2026-06-24T11:01:00Z', 'duration' => 3600],
                ],
                'next_page_token' => '',
            ]),
        ]);

        $this->artisan("zoom:backfill-attendance {$course->id} --since=2026-06-01 --apply")
            ->assertSuccessful();

        $this->assertSame(2, Schedule::where('course_id', $course->id)->count());
        $created = Schedule::where('course_id', $course->id)->where('id', '!=', $known->id)->first();
        $this->assertSame('2026-06-24', $created->start->toDateString());
        $this->assertSame('999888777', $created->zoom_meeting_id);
        $this->assertSame('999888777', $known->fresh()->zoom_meeting_id);

        $this->assertSame(1, WebinarAttendance::where('schedule_id', $created->id)->count());
        $this->assertNull($course->fresh()->zoom_meeting_id, 'курс не переключается на живой sync автоматически');
    }
}
