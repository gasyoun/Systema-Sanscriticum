<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Zoom\ZoomService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zoom Server-to-Server OAuth клиент (#78). Сетевые вызовы — через Http::fake,
 * реальные креды для тестов не нужны. Создание встреч убрано (встречи ручные),
 * клиент оставлен для чтения участников (Reports API, см. ZoomSyncAttendanceTest).
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
}
