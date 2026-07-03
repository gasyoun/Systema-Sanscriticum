<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 3 (#78): Zoom participant-вебхуки → посещаемость вебинара (WebinarAttendance).
 */
class ZoomAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-123';

    private function postZoom(array $payload): TestResponse
    {
        config(['services.zoom.webhook_secret' => self::SECRET]);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', '/api/webhooks/zoom', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-zm-request-timestamp' => $timestamp,
            'x-zm-signature' => 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, self::SECRET),
        ]), $body);
    }

    private function participant(string $event, string $meetingId, array $participant): array
    {
        return [
            'event' => $event,
            'payload' => ['object' => ['id' => $meetingId, 'participant' => $participant]],
        ];
    }

    private function schedule(string $meetingId = '500100200'): Schedule
    {
        return Schedule::create([
            'title' => 'Вебинар', 'start' => now()->subHour(), 'zoom_meeting_id' => $meetingId,
        ]);
    }

    /** @test */
    public function joined_creates_attendance_and_matches_student_by_email(): void
    {
        $schedule = $this->schedule();
        $student = User::factory()->create(['email' => 'anya@example.test']);

        $this->postZoom($this->participant('meeting.participant_joined', '500100200', [
            'participant_uuid' => 'uuid-aaa',
            'user_name' => 'Аня',
            'email' => 'anya@example.test',
            'join_time' => '2026-07-01T18:00:00Z',
        ]))->assertOk()->assertJson(['status' => 'ok']);

        $att = WebinarAttendance::where('schedule_id', $schedule->id)->first();
        $this->assertNotNull($att);
        $this->assertSame($student->id, $att->user_id);
        $this->assertSame('Аня', $att->name);
        $this->assertNotNull($att->joined_at);
    }

    /** @test */
    public function guest_without_known_email_is_recorded_without_user(): void
    {
        $this->schedule();

        $this->postZoom($this->participant('meeting.participant_joined', '500100200', [
            'participant_uuid' => 'uuid-guest',
            'user_name' => 'Гость',
            'join_time' => '2026-07-01T18:00:00Z',
        ]))->assertOk();

        $att = WebinarAttendance::first();
        $this->assertNull($att->user_id);
        $this->assertSame('Гость', $att->name);
    }

    /** @test */
    public function left_updates_same_session_with_duration(): void
    {
        $schedule = $this->schedule();

        $this->postZoom($this->participant('meeting.participant_joined', '500100200', [
            'participant_uuid' => 'uuid-aaa', 'user_name' => 'Аня', 'join_time' => '2026-07-01T18:00:00Z',
        ]))->assertOk();

        $this->postZoom($this->participant('meeting.participant_left', '500100200', [
            'participant_uuid' => 'uuid-aaa', 'user_name' => 'Аня',
            'leave_time' => '2026-07-01T19:30:00Z', 'duration' => 5400,
        ]))->assertOk();

        // Та же сессия (один participant_uuid) — одна строка, дополнена выходом.
        $this->assertSame(1, WebinarAttendance::where('schedule_id', $schedule->id)->count());
        $att = WebinarAttendance::first();
        $this->assertNotNull($att->joined_at);
        $this->assertNotNull($att->left_at);
        $this->assertSame(5400, $att->duration_seconds);
    }

    /** @test */
    public function distinct_participants_create_distinct_rows(): void
    {
        $schedule = $this->schedule();

        foreach (['uuid-1' => 'A', 'uuid-2' => 'B', 'uuid-3' => 'C'] as $uuid => $name) {
            $this->postZoom($this->participant('meeting.participant_joined', '500100200', [
                'participant_uuid' => $uuid, 'user_name' => $name, 'join_time' => '2026-07-01T18:00:00Z',
            ]))->assertOk();
        }

        $this->assertSame(3, WebinarAttendance::where('schedule_id', $schedule->id)->count());
        $this->assertSame(3, $schedule->loadCount('attendances')->attendances_count);
    }

    /** @test */
    public function participant_event_for_unknown_meeting_is_ignored(): void
    {
        $this->postZoom($this->participant('meeting.participant_joined', 'nope', [
            'participant_uuid' => 'x', 'user_name' => 'X',
        ]))->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, WebinarAttendance::count());
    }
}
