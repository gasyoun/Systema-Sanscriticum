<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Zoom-вебхук (#78): URL-валидация эндпоинта и проверка подписи.
 * Логику посещаемости проверяет ZoomAttendanceTest; запись→урок — у n8n.
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

    /** Минимальный participant-пейлоад для неизвестной встречи (резолв не найдёт). */
    private function participantPayload(string $meetingId = 'unknown'): array
    {
        return [
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => [
                'id' => $meetingId,
                'uuid' => 'occ-xyz',
                'start_time' => now()->toIso8601String(),
                'participant' => ['participant_uuid' => 'p1', 'user_name' => 'Гость'],
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
    public function unknown_meeting_is_acknowledged(): void
    {
        $this->postZoom($this->participantPayload('does-not-exist'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    /** @test */
    public function invalid_signature_is_rejected_when_secret_set(): void
    {
        config(['services.zoom.webhook_secret' => self::SECRET]);

        $body = json_encode($this->participantPayload());
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
        $this->postZoom($this->participantPayload(), sign: false)
            ->assertStatus(403);
    }

    /** @test */
    public function empty_secret_skips_signature_check(): void
    {
        // Секрет пуст → подпись не нужна (локалка); неизвестная встреча → ignored 200.
        $this->postZoom($this->participantPayload('333'), secret: '', sign: false)
            ->assertOk();
    }
}
