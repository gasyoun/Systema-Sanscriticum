<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Zoom\ZoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 1 (#78): Zoom Server-to-Server OAuth клиент. Сетевые вызовы — через Http::fake,
 * реальные креды для тестов не нужны.
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

    private function fakeZoom(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 87654321,
                'join_url' => 'https://zoom.us/j/87654321',
                'start_url' => 'https://zoom.us/s/87654321?zak=hostkey',
            ]),
        ]);
    }

    /** @test */
    public function is_configured_reflects_credentials(): void
    {
        config(['services.zoom.account_id' => null, 'services.zoom.client_id' => null, 'services.zoom.client_secret' => null]);
        $this->assertFalse(ZoomService::fromConfig()->isConfigured());

        $this->assertTrue($this->configured()->isConfigured());
    }

    /** @test */
    public function create_meeting_returns_ids_and_sends_correct_payload(): void
    {
        $this->fakeZoom();
        $start = Carbon::parse('2026-07-01 18:00:00');

        $meeting = $this->configured()->createMeeting('Вводное занятие', $start, 90, 'О плане курса');

        $this->assertSame('87654321', $meeting['id']);
        $this->assertSame('https://zoom.us/j/87654321', $meeting['join_url']);
        $this->assertStringContainsString('zak=', $meeting['start_url']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/users/me/meetings')) {
                return false;
            }

            return $request['topic'] === 'Вводное занятие'
                && $request['type'] === 2
                && $request['duration'] === 90
                && $request['start_time'] === '2026-07-01T18:00:00'
                && $request->hasHeader('Authorization', 'Bearer tok-abc');
        });
    }

    /** @test */
    public function access_token_is_cached_across_calls(): void
    {
        $this->fakeZoom();
        $svc = $this->configured();
        $start = Carbon::parse('2026-07-01 18:00:00');

        $svc->createMeeting('A', $start, 60);
        $svc->createMeeting('B', $start, 60);

        // Два создания встречи — но токен запрашивается ОДИН раз (кэш).
        Http::assertSentCount(3); // 1 oauth + 2 meetings
    }

    /** @test */
    public function create_meeting_throws_when_not_configured(): void
    {
        config(['services.zoom.account_id' => null, 'services.zoom.client_id' => null, 'services.zoom.client_secret' => null]);

        $this->expectException(RuntimeException::class);
        ZoomService::fromConfig()->createMeeting('X', Carbon::now(), 60);
    }

    /** @test */
    public function create_meeting_throws_on_api_error(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response(['message' => 'Validation failed'], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->configured()->createMeeting('X', Carbon::now()->addDay(), 60);
    }
}
