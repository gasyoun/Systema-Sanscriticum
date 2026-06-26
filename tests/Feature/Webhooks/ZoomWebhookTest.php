<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 2 (#78): Zoom-вебхук recording.completed → запись на Schedule + урок;
 * проверка подписи и URL-валидации.
 */
class ZoomWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-123';

    private const TS = '1700000000';

    private function postZoom(array $payload, ?string $secret = self::SECRET, bool $sign = true): TestResponse
    {
        config(['services.zoom.webhook_secret' => $secret]);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        if ($sign && $secret) {
            $headers['x-zm-request-timestamp'] = self::TS;
            $headers['x-zm-signature'] = 'v0='.hash_hmac('sha256', 'v0:'.self::TS.':'.$body, $secret);
        }

        return $this->call('POST', '/api/webhooks/zoom', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    private function recordingPayload(string $meetingId, string $shareUrl = 'https://zoom.us/rec/share/abc'): array
    {
        return [
            'event' => 'recording.completed',
            'payload' => ['object' => [
                'id' => $meetingId,
                'topic' => 'Вебинар',
                'share_url' => $shareUrl,
                'recording_files' => [
                    ['file_type' => 'MP4', 'play_url' => 'https://zoom.us/rec/play/mp4'],
                ],
            ]],
        ];
    }

    /** @test */
    public function url_validation_returns_encrypted_token(): void
    {
        $resp = $this->postZoom([
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'plain-abc'],
        ], sign: false);

        $resp->assertOk()
            ->assertJson([
                'plainToken' => 'plain-abc',
                'encryptedToken' => hash_hmac('sha256', 'plain-abc', self::SECRET),
            ]);
    }

    /** @test */
    public function recording_completed_attaches_recording_and_creates_lesson(): void
    {
        $course = Course::factory()->create();
        $schedule = Schedule::create([
            'title' => 'Занятие 3',
            'start' => now()->subHour(),
            'course_id' => $course->id,
            'zoom_meeting_id' => '987654321',
        ]);

        $this->postZoom($this->recordingPayload('987654321'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'schedule_id' => $schedule->id]);

        $schedule->refresh();
        $this->assertSame('https://zoom.us/rec/share/abc', $schedule->zoom_recording_url);
        $this->assertNotNull($schedule->zoom_recording_received_at);

        $lesson = Lesson::where('course_id', $course->id)->first();
        $this->assertNotNull($lesson);
        $this->assertSame('https://zoom.us/rec/share/abc', $lesson->video_url);
        $this->assertSame('Занятие 3', $lesson->title);
    }

    /** @test */
    public function recording_completed_is_idempotent(): void
    {
        $course = Course::factory()->create();
        Schedule::create([
            'title' => 'Занятие', 'start' => now()->subHour(),
            'course_id' => $course->id, 'zoom_meeting_id' => '111',
        ]);

        $this->postZoom($this->recordingPayload('111'))->assertOk();
        $this->postZoom($this->recordingPayload('111'))->assertOk();

        // Повторная доставка не плодит дубль урока.
        $this->assertSame(1, Lesson::where('course_id', $course->id)->count());
    }

    /** @test */
    public function falls_back_to_mp4_play_url_without_share_url(): void
    {
        $course = Course::factory()->create();
        $schedule = Schedule::create([
            'title' => 'Занятие', 'start' => now()->subHour(),
            'course_id' => $course->id, 'zoom_meeting_id' => '222',
        ]);

        $payload = $this->recordingPayload('222');
        unset($payload['payload']['object']['share_url']);

        $this->postZoom($payload)->assertOk();

        $this->assertSame('https://zoom.us/rec/play/mp4', $schedule->fresh()->zoom_recording_url);
    }

    /** @test */
    public function unknown_meeting_is_acknowledged_without_lesson(): void
    {
        $this->postZoom($this->recordingPayload('does-not-exist'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(0, Lesson::count());
    }

    /** @test */
    public function invalid_signature_is_rejected_when_secret_set(): void
    {
        config(['services.zoom.webhook_secret' => self::SECRET]);

        $body = json_encode($this->recordingPayload('111'));
        $this->call('POST', '/api/webhooks/zoom', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-zm-request-timestamp' => self::TS,
            'x-zm-signature' => 'v0=deadbeef',
        ]), $body)->assertStatus(403);
    }

    /** @test */
    public function missing_signature_is_rejected_when_secret_set(): void
    {
        $this->postZoom($this->recordingPayload('111'), sign: false)
            ->assertStatus(403);
    }

    /** @test */
    public function empty_secret_skips_signature_check(): void
    {
        $course = Course::factory()->create();
        Schedule::create([
            'title' => 'Занятие', 'start' => now()->subHour(),
            'course_id' => $course->id, 'zoom_meeting_id' => '333',
        ]);

        // Секрет пуст → подпись не нужна (локалка).
        $this->postZoom($this->recordingPayload('333'), secret: '', sign: false)
            ->assertOk();
    }
}
