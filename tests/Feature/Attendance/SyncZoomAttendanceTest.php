<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pull-сверка zoom:sync-attendance: тянет участников прошедших запусков через
 * Reports API и апсертит посещаемость (страховка вебхука).
 */
class SyncZoomAttendanceTest extends TestCase
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
    public function pulls_participants_and_matches_student_by_email(): void
    {
        $course = Course::factory()->create(['zoom_meeting_id' => '850850850']);
        $schedule = Schedule::create([
            'title' => 'Занятие', 'course_id' => $course->id,
            'start' => now()->setTime(10, 0), 'end' => now()->setTime(12, 0),
        ]);
        $student = User::factory()->create(['email' => 'ivan@example.test']);

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/850850850/instances' => Http::response([
                'meetings' => [['uuid' => 'occ-AAA==', 'start_time' => now()->setTime(10, 0)->toIso8601String()]],
            ]),
            'api.zoom.us/v2/report/meetings/*/participants*' => Http::response([
                'participants' => [
                    ['participant_uuid' => 'p1', 'name' => 'Иван', 'user_email' => 'ivan@example.test',
                        'join_time' => now()->setTime(10, 1)->toIso8601String(),
                        'leave_time' => now()->setTime(11, 55)->toIso8601String(), 'duration' => 6840],
                    ['participant_uuid' => 'p2', 'name' => 'Гость', 'user_email' => '',
                        'join_time' => now()->setTime(10, 5)->toIso8601String(), 'duration' => 600],
                ],
                'next_page_token' => '',
            ]),
        ]);

        $this->artisan('zoom:sync-attendance --days=3')->assertSuccessful();

        $this->assertSame(2, WebinarAttendance::where('schedule_id', $schedule->id)->count());

        $known = WebinarAttendance::where('zoom_participant_uuid', 'p1')->first();
        $this->assertSame($student->id, $known->user_id);
        $this->assertSame(6840, $known->duration_seconds);
        $this->assertNotNull($known->joined_at);
        $this->assertNotNull($known->left_at);

        $guest = WebinarAttendance::where('zoom_participant_uuid', 'p2')->first();
        $this->assertNull($guest->user_id);

        // Занятие привязалось к запуску по uuid.
        $this->assertSame('occ-AAA==', $schedule->fresh()->zoom_occurrence_uuid);
    }

    /** @test */
    public function noop_when_not_configured(): void
    {
        config(['services.zoom.account_id' => null, 'services.zoom.client_id' => null, 'services.zoom.client_secret' => null]);
        Http::fake();

        $this->artisan('zoom:sync-attendance')->assertSuccessful();
        Http::assertNothingSent();
    }
}
