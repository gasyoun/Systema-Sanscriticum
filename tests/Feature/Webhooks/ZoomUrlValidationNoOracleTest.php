<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H5082 (remediation confirmed H5046) · zoom-url-validation-hmac-signing-oracle.
 *
 * Regression: endpoint.url_validation больше не является неподлинным
 * HMAC-оракулом. Прежде поведение (Nv14): челлендж отвечался ДО проверки
 * подписи, plainToken выбирал атакующий — строка `v0:{ts}:{forged body}`
 * получала готовую подпись для сфабрикованного события (byte-match с
 * hash_hmac(secret, attacker_message)).
 *
 * Теперь: неподписанный url_validation → 403 без какого-либо HMAC в теле;
 * подписанный → штатный echo-контракт Zoom (bootstrap продолжает работать).
 */
class ZoomUrlValidationNoOracleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'testsecret';

    private const TS = '1700000000';

    private function postZoom(array $payload, bool $sign): TestResponse
    {
        config(['services.zoom.webhook_secret' => self::SECRET]);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        if ($sign) {
            $headers['x-zm-request-timestamp'] = self::TS;
            $headers['x-zm-signature'] = 'v0='.hash_hmac('sha256', 'v0:'.self::TS.':'.$body, self::SECRET);
        }

        return $this->call('POST', '/api/webhooks/zoom', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /** @test */
    public function unsigned_url_validation_is_rejected_and_leaks_no_hmac(): void
    {
        // Классический оракул-пейлоад: plainToken = точная рамка подписи
        // сфабрикованного события participant_joined.
        $attackerPlain = 'v0:'.self::TS.':{"event":"meeting.participant_joined","payload":{"object":{"participant":{"user_name":"forged"}}}}';

        $response = $this->postZoom([
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => $attackerPlain, 'urlValidationToken' => 'anything'],
        ], sign: false);

        $response->assertStatus(403);

        // Оракул закрыт: HMAC секрета над НИЖЕ выбранной атакующим строкой
        // не возвращается ни в каком виде.
        $this->assertStringNotContainsString(
            hash_hmac('sha256', $attackerPlain, self::SECRET),
            $response->getContent(),
            'url_validation не должен отдавать HMAC(secret, attacker-chosen message)',
        );
        $this->assertNull($response->json('encryptedToken'));
    }

    /** @test */
    public function wrong_signature_url_validation_is_rejected(): void
    {
        config(['services.zoom.webhook_secret' => self::SECRET]);

        $body = json_encode(['event' => 'endpoint.url_validation', 'payload' => ['plainToken' => 'plain-abc']]);
        $this->call('POST', '/api/webhooks/zoom', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'x-zm-request-timestamp' => self::TS,
            'x-zm-signature' => 'v0='.hash_hmac('sha256', 'v0:'.self::TS.':wrong-body', self::SECRET),
        ]), $body)->assertStatus(403);
    }

    /** @test */
    public function signed_url_validation_keeps_zoom_echo_contract(): void
    {
        // Реальный bootstrap продолжает работать: подписанный Zoom'ом челлендж
        // получает plainToken + encryptedToken = HMAC-SHA256(plainToken, secret)
        // — ровно контракт из официального zoom/webhook-sample.
        $response = $this->postZoom([
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'plain-abc'],
        ], sign: true);

        $response->assertOk()->assertJson([
            'plainToken' => 'plain-abc',
            'encryptedToken' => hash_hmac('sha256', 'plain-abc', self::SECRET),
        ]);
    }
}
