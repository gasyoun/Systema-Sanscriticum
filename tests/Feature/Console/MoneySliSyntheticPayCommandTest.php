<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

// [secret-scan-ok] throwaway test-only RSA keypair (never the real Tochka bank key),
// same convention as tests/Feature/Webhooks/TochkaWebhookSliFallbackTest.php.

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4672 — money:sli-synthetic-pay end to end: the command signs a mocked
 * Tochka webhook with the dedicated SLI keypair and POSTs it at the real
 * route; Http::fake() forwards that POST into the app's own HTTP kernel
 * (same middleware/controller a real bank delivery would hit) instead of
 * hand-mutating the database, so this exercises the real
 * WebhookController -> Payment::grantAccess() path end to end.
 *
 * Covers the mission's "negative test: a stopped webhook is caught by the
 * probe once outside the grace window" requirement (sustained-failure case
 * below) plus the flap-tolerance the retry loop exists for (transient case).
 */
class MoneySliSyntheticPayCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SLI_TEST_PRIVATE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCq4I/U79w6Gnq/
sbLGek2RjUD5Ze/16NXC31YPj7EUJWlyHstNG9KKR/X7UQK5sI2npvAgh/vhfZzw
ITeqb+HClIWYOXz3kSud+aEPKm4BrlDFG/tK0PtxiY03+ImvBmW5Zu4PA9/s4Xvg
8KP3MZ63ArhcbqOUcWvjlb7xbGa4qi4in1MPNkefNS3P6CJMkIgazZmHwA5pwn1K
9hfpD2RLbNAUgQE3DjURXXsU34A00CosbZzdoDFhAvt9xlOv0HurBYbWvEJn252m
9mXre2iQZjldTFh7/bNozscXQI+BJJg4yNgIy/PwKWHWL4wWRJnip2yL3xKu9C4r
mOLLCOdzAgMBAAECggEAHFPS9FF5OFaob7v1L6sZzd3VXiL0h1c8jKw6l6TSDc1v
8B/DwzowCoWIdUvGQGNQ8HCf0TzJ2GVbDYHsOQCINBosFYK+QUpbKTq3ZQy7JOMx
d6O+YnZHoNhVRWiZ5p6QYY57O5kAV8Q/pZgvDm026w7z4jrjOlodMSLjfyFh3A3w
8hAKwjKEClNvsrppUIyPD3mtUstBXBLtFupKtagXmBsuSOkZ+XwyA8xePANlx1I0
TxdhlKxA/uJK5uDl1nsDdw3Jhf4Xkgk5S0MwUztwsFz7fqi607ckrqQ0guuG1uvp
gyGy8CiEtGn+QVhDBKgP2cCJtR7fGVRBGZuVaSK9RQKBgQDUlpH3+SrDIi/D5dhu
FYvIj/seuv+2SwsBlrP4BxrlcQyt+hKXhgzHsgfauzY5yuVMGD76AI28CHc8oqxc
y6xT/D8SpevZ2e2BwO8xowih/YmawMlpUphoDIv/ytIqED9jO8gyYgedtZkAyRhP
VrXy6QmCfNTbtRM0ekTr/xIgnQKBgQDNxXm24cCU51mCanodBiPfUO8jE1UX/E7c
F51JaeH+H7D6+YpBVnj8XU+R5atafvuwOudIjqKEurDQrV7SdzaMFT6ikVxV78e9
nJIqMy0vLKQ8mQrbi/vgQotlYTB3dMP5FBDSSHgLCnefSOetvBRuT3uklF6Bkl6+
k5BI65sDTwKBgChGXmEcU32kfGggo2A3tMPKg0jPJKLklLE4W+AheHb/c+eB+QO7
4a/zioll7mAEkGxaK5QxhqiY8f4K05zA+WTv5QMjbAtZviVW5/n/aSNHZUpsO7w4
aadMuTk8s5REf73NFaB18ftu7A26C2D8jHv4qlSOUcVOCNVoVKZhLI4BAoGBALHv
JGvnVR+t2nHy3vuAFr8B/ngHPJsMG6koZmNYQwr7no+3/zy2qNIZYjgYMQ+FJOFk
XiEY7iH2SfV5Jbi7S5jguhPbvMu3F7K31JDXRig34yFfecsVhk2LXXziCQYTG2+k
UVN1RRDPEVfUtDpAnC4zXwiXIA3NY05KzgawbY/zAoGBAKjOOj0B6S1NWetIRj3R
hhsjNFLAYuHDYLqJojkxlSqTtNi9xW2Lz6Z9LNpZ7mXHu4Z46l6pjkJO6EvGYJIr
dxa94pU9LkAD0zYKhFe4h5Krf9G1zy9MbOA07004URd6JOwF3jXfm2cXmiqNJX2U
v7lRZFHezmXBj/VOt625fTzd
-----END PRIVATE KEY-----
PEM;

    private const SLI_TEST_JWK = '{"kty":"RSA","e":"AQAB","n":"quCP1O_cOhp6v7GyxnpNkY1A-WXv9ejVwt9WD4-xFCVpch7LTRvSikf1-1ECubCNp6bwIIf74X2c8CE3qm_hwpSFmDl895ErnfmhDypuAa5QxRv7StD7cYmNN_iJrwZluWbuDwPf7OF74PCj9zGetwK4XG6jlHFr45W-8WxmuKouIp9TDzZHnzUtz-giTJCIGs2Zh8AOacJ9SvYX6Q9kS2zQFIEBNw41EV17FN-ANNAqLG2c3aAxYQL7fcZTr9B7qwWG1rxCZ9udpvZl63tokGY5XUxYe_2zaM7HF0CPgSSYOMjYCMvz8Clh1i-MFkSZ4qdsi98SrvQuK5jiywjncw"}';

    private string $tsvPath;

    private string $tgStatePath;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->tsvPath = storage_path('app/money_sli/test_'.uniqid('synth_', true).'.tsv');
        $this->tgStatePath = storage_path('app/money_sli/test_'.uniqid('tgstate_', true).'.json');

        config([
            'features.money_sli_synthetic_pay' => true,
            'money_sli.webhook_private_key' => self::SLI_TEST_PRIVATE_PEM,
            'services.tochka.sli_webhook_public_key' => self::SLI_TEST_JWK,
            'money_sli.attempts' => 3,
            'money_sli.attempt_pause_seconds' => 0,
            'money_sli.tsv_path' => $this->tsvPath,
            'money_sli.tg_state_path' => $this->tgStatePath,
            'money_sli.daily_ping_url' => '',
            'services.telegram.bot_token' => '',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->tsvPath);
        @unlink($this->tgStatePath);
        parent::tearDown();
    }

    /**
     * Forwards a faked outbound webhook POST into the app's own kernel, so
     * the command exercises the real WebhookController + grantAccess path
     * instead of a hand-rolled mock of "what the webhook would have done".
     */
    private function fakeWebhookForwarding(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/webhooks/tochka')) {
                $response = $this->call(
                    'POST',
                    '/api/webhooks/tochka',
                    [], [], [],
                    ['CONTENT_TYPE' => 'application/jwt'],
                    $request->body()
                );

                return Http::response($response->getContent(), $response->getStatusCode());
            }

            return Http::response('ok', 200);
        });
    }

    private function readTsvRows(): array
    {
        if (! is_file($this->tsvPath)) {
            return [];
        }
        // rtrim only the trailing newline — trim() would also eat a trailing
        // tab on the LAST line's empty final field (e.g. an empty "notes"),
        // silently shortening only that one row and breaking array_combine.
        $lines = array_filter(explode("\n", rtrim(file_get_contents($this->tsvPath), "\n")), fn ($l) => $l !== '');
        $header = str_getcsv(array_shift($lines), "\t");
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_combine($header, str_getcsv($line, "\t"));
        }

        return $rows;
    }

    /** @test */
    public function a_healthy_run_grants_then_revokes_access_and_logs_ok(): void
    {
        $this->fakeWebhookForwarding();

        $this->artisan('money:sli-synthetic-pay')->assertExitCode(0);

        $user = User::where('email', 'h4672-sli-probe@example.invalid')->firstOrFail();
        // finally{} always revokes + reverts paid->canceled, even on success —
        // the probe must leave zero standing access/revenue footprint.
        $this->assertFalse($user->groups()->exists());
        $this->assertSame('canceled', $user->payments()->latest('id')->first()->status);

        $rows = $this->readTsvRows();
        $this->assertCount(1, $rows);
        $this->assertSame('synthetic_pay', $rows[0]['check']);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame('1', $rows[0]['attempts']);
    }

    /** @test */
    public function a_transient_failure_recovers_within_the_retry_budget_without_alerting(): void
    {
        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            if (str_contains($request->url(), '/api/webhooks/tochka')) {
                $attempt++;
                if ($attempt === 1) {
                    return Http::response('Server error', 500);
                }
                $response = $this->call(
                    'POST',
                    '/api/webhooks/tochka',
                    [], [], [],
                    ['CONTENT_TYPE' => 'application/jwt'],
                    $request->body()
                );

                return Http::response($response->getContent(), $response->getStatusCode());
            }

            return Http::response('ok', 200);
        });

        $this->artisan('money:sli-synthetic-pay')->assertExitCode(0);

        $rows = $this->readTsvRows();
        $this->assertCount(1, $rows);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame('2', $rows[0]['attempts'], 'first attempt failed, second recovered — proves flap tolerance');
    }

    /** @test */
    public function a_sustained_failure_exhausts_all_attempts_pages_and_hits_the_fail_heartbeat(): void
    {
        config([
            'money_sli.attempts' => 2,
            'money_sli.daily_ping_url' => 'https://uptime.example.invalid/api/v1/heartbeat/test-token',
            'services.telegram.bot_token' => 'test-bot-token',
            'money_sli.telegram_chat_id' => '123456789',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/webhooks/tochka')) {
                return Http::response('Invalid signature', 401);
            }
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            return Http::response('ok', 200);
        });

        $this->artisan('money:sli-synthetic-pay')->assertExitCode(1);

        $rows = $this->readTsvRows();
        $this->assertCount(1, $rows);
        $this->assertSame('fail', $rows[0]['status']);
        $this->assertSame('2', $rows[0]['attempts']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/heartbeat/test-token/fail'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));

        $user = User::where('email', 'h4672-sli-probe@example.invalid')->firstOrFail();
        $this->assertFalse($user->groups()->exists());
    }
}
