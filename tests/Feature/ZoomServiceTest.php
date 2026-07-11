<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Webinar\WebinarProvider;
use App\Services\Zoom\ZoomService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zoom Server-to-Server OAuth клиент (#78). Сетевые вызовы — через Http::fake,
 * реальные креды для тестов не нужны. Создание встреч убрано (встречи ручные),
 * клиент оставлен для чтения участников (Reports API, см. ZoomSyncAttendanceTest).
 *
 * WebinarProvider-методы (GC-B3, H601) — createMeeting/fetchParticipants/
 * normalizeWebhook — покрыты ниже отдельно; существующие тесты выше не менялись.
 */
class ZoomServiceTest extends TestCase
{
    private function configured(): ZoomService
    {
        config([
            'services.zoom.account_id' => 'acc-123',
            'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'secret',
        ]);

        return ZoomService::fromConfig();
    }

    /** @test */
    public function is_configured_reflects_credentials(): void
    {
        config(['services.zoom.account_id' => null, 'services.zoom.client_id' => null, 'services.zoom.client_secret' => null]);
        $this->assertFalse(ZoomService::fromConfig()->isConfigured());

        $this->assertTrue($this->configured()->isConfigured());
    }

    /** @test */
    public function access_token_is_cached_across_calls(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600]),
        ]);

        $svc = $this->configured();
        $this->assertSame('tok-abc', $svc->accessToken());
        $this->assertSame('tok-abc', $svc->accessToken());

        // Токен запрашивается один раз (кэш ~55 мин).
        Http::assertSentCount(1);
    }

    /** @test */
    public function implements_webinar_provider(): void
    {
        $this->assertInstanceOf(WebinarProvider::class, $this->configured());
    }

    /** @test */
    public function create_meeting_returns_normalized_shape(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 987654321, 'join_url' => 'https://zoom.us/j/987654321', 'start_url' => 'https://zoom.us/s/987654321',
            ]),
        ]);

        $meeting = $this->configured()->createMeeting(['topic' => 'Занятие 1']);

        $this->assertSame([
            'meeting_id' => '987654321',
            'join_url' => 'https://zoom.us/j/987654321',
            'start_url' => 'https://zoom.us/s/987654321',
        ], $meeting);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.zoom.us/v2/users/me/meetings'
            && $request['topic'] === 'Занятие 1');
    }

    /** @test */
    public function fetch_participants_delegates_to_meeting_participants(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/report/meetings/*/participants*' => Http::response([
                'participants' => [['participant_uuid' => 'p1']],
                'next_page_token' => '',
            ]),
        ]);

        $svc = $this->configured();
        $this->assertSame($svc->meetingParticipants('occ-1'), $svc->fetchParticipants('occ-1'));
        $this->assertSame([['participant_uuid' => 'p1']], $svc->fetchParticipants('occ-1'));
    }

    /** @test */
    public function normalize_webhook_extracts_participant_event(): void
    {
        $payload = [
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => [
                'id' => '111', 'uuid' => 'occ-xyz', 'start_time' => '2026-07-11T10:00:00Z',
                'participant' => ['participant_uuid' => 'p1', 'user_name' => 'Гость'],
            ]],
        ];

        $normalized = $this->configured()->normalizeWebhook($payload);

        $this->assertSame([
            'event' => 'meeting.participant_joined',
            'meeting_id' => '111',
            'occurrence_uuid' => 'occ-xyz',
            'event_time' => '2026-07-11T10:00:00Z',
            'participant' => ['participant_uuid' => 'p1', 'user_name' => 'Гость'],
        ], $normalized);
    }

    /** @test */
    public function normalize_webhook_returns_null_for_other_events(): void
    {
        $this->assertNull($this->configured()->normalizeWebhook(['event' => 'recording.completed']));
    }
}
