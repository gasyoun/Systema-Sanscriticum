<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ServerGuards\SoftAlertWebhookNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SoftAlertWebhookNotifierTest extends TestCase
{
    public function test_no_url_is_noop(): void
    {
        config(['cabinet_probe.soft_webhook_url' => '']);
        $r = (new SoftAlertWebhookNotifier)->notify('guards', 'abc', [
            ['message' => 'guards/auto-deploy: test', 'severity' => 'soft'],
        ]);
        $this->assertFalse($r['attempted']);
        $this->assertFalse($r['ok']);
    }

    public function test_posts_payload_with_secret_header(): void
    {
        config([
            'cabinet_probe.soft_webhook_url' => 'https://example.test/soft',
            'cabinet_probe.soft_webhook_secret' => 's3cret',
            'cabinet_probe.soft_webhook_timeout' => 3,
            'app.url' => 'https://samskrte.ru',
        ]);
        Http::fake([
            'https://example.test/soft' => Http::response(['ok' => true], 202),
        ]);

        $r = (new SoftAlertWebhookNotifier)->notify('guards', 'fp123', [
            ['message' => 'guards/auto-deploy: [blocked-preflight]', 'severity' => 'soft'],
        ]);

        $this->assertTrue($r['attempted']);
        $this->assertTrue($r['ok']);
        $this->assertSame(202, $r['status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.test/soft'
                && $request->hasHeader('X-Soft-Alert-Secret', 's3cret')
                && $request['event'] === 'cabinet.soft_alert'
                && $request['schema_version'] === 1
                && $request['scope'] === 'guards'
                && $request['fingerprint'] === 'fp123'
                && str_contains($request['failures'][0]['message'], 'blocked-preflight');
        });
    }

    public function test_http_failure_is_fail_open(): void
    {
        config(['cabinet_probe.soft_webhook_url' => 'https://example.test/soft']);
        Http::fake([
            'https://example.test/soft' => Http::response('nope', 500),
        ]);

        $r = (new SoftAlertWebhookNotifier)->notify('guards', 'fp', [
            ['message' => 'x', 'severity' => 'soft'],
        ]);
        $this->assertTrue($r['attempted']);
        $this->assertFalse($r['ok']);
        $this->assertSame(500, $r['status']);
    }
}
