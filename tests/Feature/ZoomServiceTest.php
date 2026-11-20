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

    /**
     * Два аккаунта школы: основной (main) + доп. слот zoom2 (второй аккаунт).
     */
    private function configuredWithExtra(): void
    {
        config([
            'services.zoom.account_id' => 'acc-123',
            'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'secret',
            'services.zoom.extra_accounts' => [
                'zoom2' => [
                    'account_id' => 'acc-2',
                    'client_id' => 'cid2',
                    'client_secret' => 'secret2',
                ],
            ],
        ]);
    }

    /** @test */
    public function instances_are_searched_across_accounts_when_main_does_not_know_meeting(): void
    {
        $this->configuredWithExtra();

        // Комната живёт в доп. аккаунте: основной отвечает 404, zoom2 — списком запусков.
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*past_meetings/222*' => Http::sequence()
                ->push(['meetings' => []], 404)
                ->push(['meetings' => [['uuid' => 'u-2', 'start_time' => '2026-10-10T10:00:00Z']]]),
        ]);

        $resolved = ZoomService::fromConfig()->pastMeetingInstancesAcrossAccounts('222');

        $this->assertSame('zoom2', $resolved['account']);
        $this->assertCount(1, $resolved['meetings']);
    }

    /** @test */
    public function past_meeting_instances_contract_returns_meetings_of_extra_account(): void
    {
        $this->configuredWithExtra();

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*past_meetings/222*' => Http::sequence()
                ->push(['meetings' => []], 404)
                ->push(['meetings' => [['uuid' => 'u-2', 'start_time' => '2026-10-10T10:00:00Z']]]),
        ]);

        $this->assertCount(1, ZoomService::fromConfig()->pastMeetingInstances('222'));
    }

    /** @test */
    public function participants_use_account_resolved_by_instances_without_extra_calls(): void
    {
        $this->configuredWithExtra();

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*past_meetings/222*' => Http::sequence()
                ->push(['meetings' => []], 404)
                ->push(['meetings' => [['uuid' => 'u-2', 'start_time' => '2026-10-10T10:00:00Z']]]),
            '*report/meetings/u-2*' => Http::response(['participants' => [['name' => 'Гость zoom2']]]),
        ]);

        $svc = ZoomService::fromConfig();
        $svc->pastMeetingInstances('222');
        $participants = $svc->meetingParticipants('u-2');

        $this->assertSame('Гость zoom2', $participants[0]['name']);

        // memo от instances: report-запрос ровно один, сразу в найденный аккаунт,
        // без пробного 404 основного.
        $reports = 0;
        Http::assertSent(function ($request) use (&$reports): bool {
            if (str_contains($request->url(), '/report/meetings/')) {
                $reports++;
            }

            return true;
        });
        $this->assertSame(1, $reports);
    }

    /** @test */
    public function participants_fall_through_to_account_that_knows_the_uuid(): void
    {
        $this->configuredWithExtra();

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*report/meetings/u-x*' => Http::sequence()
                ->push(['participants' => []], 404)
                ->push(['participants' => [['name' => 'Гость zoom2']]]),
        ]);

        $participants = ZoomService::fromConfig()->meetingParticipants('u-x');

        $this->assertSame('Гость zoom2', $participants[0]['name']);
    }
}
