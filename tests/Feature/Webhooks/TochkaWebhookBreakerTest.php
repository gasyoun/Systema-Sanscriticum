<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4930 (E002 layer 1): sentinel_breaker over the Tochka webhook's paid->access
 * grant, the negative test the DoD asks for — the (N+1)th mutation in the
 * window is refused, not granted, and the refusal screams via the money
 * channel (guards:money-breaker-alarm). Mirrors MadelineSyncBreakerTest's
 * fake-CLI shape (argv log + a control file for the exit code), the pinned
 * wiring pattern for every sentinel_breaker caller in this repo.
 *
 * [secret-scan-ok] — the RSA pair below is the same one-off test fixture
 * already committed in TochkaWebhookTest.php (not a real credential).
 */
class TochkaWebhookBreakerTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PRIVATE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQC8oaiuhHot9iJI
3z+xch7KDj8IVAPJOdmIQYE4Js5iw1GmL0rYq0hfSFXO2QqTww7qXiTf3IXCg29L
Npb6Uk4Ryz8/V+eihRS0sSfiQc7VQ+yv/KS6gPbn4gPiW/B8tzfAYDf69FMgCXd7
B9LL6yW6GjgvePN9Fyc+OKFOAerQFGIIGbYio0n3U+P+pLi5deQ6vxJh2gDHOIx8
YB/gEiynUMItLbJrzdaAmKshEjb3GVemHd+MQ9/7Ds1wQJqUoX7TqRGp9MipELk9
qu/Bu92F5FZIeaGUVJBpxFXhiMAGlsaA/GwVCQuE0//8kx+5sahldy2X2571Mgx5
nHsXnSUZAgMBAAECggEABHYWVTpQ4XFm0i5lhT7bt4+qsfm6tTGnEW/rLHbOfst7
zOBldsZmScqeLOw5MdF1MtnTKXA/wZ/2K+M4oub7bbRO5KKhmdhn6vYdqV5BFA4t
NORWyQpvzIAt81aVU33J1cTwzgClTqaqqsA+nhALrmEcXxMPPzAi/3e7aOrmsNEg
Ktw0boWmgsGQpHgGl6XFNt6lejhUSzBt1svB4nT1sFyRasWhO0N7SZtkSEaW9gfi
SKEwAzW4pLZ8Nv8MwdA2/2eHvBa9eVCiUzr08lxGnEDdbiOET8DFLyPctg/Ekk27
2q3JT7TzLjuk8j2c/FDA2n/AjsjmOn9vskZrSjHE2QKBgQDuEzkNvv84ftjEbpmA
SVGiToPHICxHAonr21vwu4CkeFeE9JEKpX/rjbK3uAqG3srRH1doeFjSzvPJbOp7
iKJoAau7915EAFeoJ6FNBXPp1orT5H2ro9TPBy1CTNwHUuQ5yxAv+BMRxmrr5KUd
8Jb/Wd652TZuMQq5CLE5SrRnBwKBgQDK1W95XQVXwx8QCCu/Q3z40NZ8/f6R4Phz
LCYYULE4BvZNhBU4+BC3kavVE1UcAgkbFjj7JCXfhB2BSnoqy8Daw7LS8+nU6xmU
k63G2ngExjqC4qvywb6xNtCTwi4adzKwUTvYG+09mEmAyeGVHwf4HanI4Ranc5w6
Yl2e2Z3q3wKBgQDQAPWJIAXGq3TicqsknWp4f1a9JEvrIrmz2uzCMGAd0pLMtA0B
G0XfXOb3gxGXcpILEfIBcZxRWsU+iC16Dw+uBT+xM1gl25K6dR2FuKzkcjDLHsf5
rWMiGmgdlB9tOqvyHoufDYRDtHL4dMUamnii0zc4cyIONkTjE0gcATwLAwKBgQCx
f5vgodWWGotpVS0rYBzSBLdehEstT6k76IuhxaOAOx95cDe+Nd8zNUgg250kOGfN
i2Hr7JM0CYJkbU+BefLXvmAUGR0slVw6WA2/sdlLnEkB1ujQNFny7NwUId6EjIEQ
KNZs5Ot0dnsEOCavf4tSxmqY/tj7SsGRmhkBdMCsEwKBgQDOFyeLleSJsS751xNC
3WuMCgRz2kvQf2FszH2GnLibUr3uM46tpahreeCiWwKE/cbPEaCI20XkMP9Yq7pP
hCzeopgb4Ex0LMSgXQOmdmIxo2cRakNhyiNJkJfKwul9TdaDljpcPDYJhJpYUQby
6le5puetsuVUtcLUQMNNOTBRYw==
-----END PRIVATE KEY-----
PEM;

    private const TEST_JWK = '{"kty":"RSA","e":"AQAB","n":"vKGoroR6LfYiSN8_sXIeyg4_CFQDyTnZiEGBOCbOYsNRpi9K2KtIX0hVztkKk8MO6l4k39yFwoNvSzaW-lJOEcs_P1fnooUUtLEn4kHO1UPsr_ykuoD25-ID4lvwfLc3wGA3-vRTIAl3ewfSy-sluho4L3jzfRcnPjihTgHq0BRiCBm2IqNJ91Pj_qS4uXXkOr8SYdoAxziMfGAf4BIsp1DCLS2ya83WgJirIRI29xlXph3fjEPf-w7NcECalKF-06kRqfTIqRC5ParvwbvdheRWSHmhlFSQacRV4YjABpbGgPxsFQkLhNP__JMfubGoZXctl9ue9TIMeZx7F50lGQ"}';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('fake breaker CLI is a POSIX shell script');
        }
        Queue::fake();
        Mail::fake();
        config(['services.tochka.webhook_public_key' => self::TEST_JWK]);

        $this->tmp = sys_get_temp_dir().'/h4930-breaker-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp.'/state');
        File::put($this->tmp.'/fake.sh', "#!/bin/sh\n"
            ."echo \"\$*\" >> \"{$this->tmp}/calls\"\n"
            ."echo \"\$SENTINEL_BREAKER_ALARM_CMD\" > \"{$this->tmp}/alarm_cmd\"\n"
            ."[ -f \"{$this->tmp}/refuse\" ] && [ \"\$1\" = check ] && exit 1\n"
            ."exit 0\n");
        config([
            'features.money_mutation_breaker' => true,
            'services.sentinel_breaker.enabled' => true,
            'services.sentinel_breaker.python' => '/bin/sh',
            'services.sentinel_breaker.bin' => $this->tmp.'/fake.sh',
            'services.sentinel_breaker.state_dir' => $this->tmp.'/state',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function calls(): array
    {
        return is_file($this->tmp.'/calls') ? file($this->tmp.'/calls', FILE_IGNORE_NEW_LINES) : [];
    }

    private function sign(array $payload): string
    {
        return JWT::encode($payload, self::TEST_PRIVATE_PEM, 'RS256');
    }

    private function postJwt(string $jwt)
    {
        return $this->call('POST', '/api/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'application/jwt'], $jwt);
    }

    private function makePayment(): Payment
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);
    }

    /** Flag OFF (default) — CLI never spawns, behaviour byte-identical to pre-H4930. */
    public function test_flag_off_never_spawns_the_breaker(): void
    {
        config(['features.money_mutation_breaker' => false]);
        $payment = $this->makePayment();
        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);

        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame([], $this->calls());
    }

    /** Healthy breaker (no freeze on disk) grants access as before, one `check` call. */
    public function test_healthy_breaker_still_grants_access(): void
    {
        $payment = $this->makePayment();
        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);

        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $calls = $this->calls();
        $this->assertStringStartsWith('check tochka_grant ', $calls[0]);
        $this->assertStringContainsString('--class money_mutation', $calls[0]);
        $this->assertStringStartsWith('record tochka_grant ', $calls[1]);
    }

    /**
     * The negative test the DoD names: the (N+1)th grant is refused. Access
     * is NOT granted, the delivery is journaled as breaker_refused (not
     * applied), the bank still gets a 200 (idempotent retry-safe), and the
     * money-channel alarm command is wired as ALARM_CMD.
     */
    public function test_frozen_breaker_refuses_the_grant_and_screams(): void
    {
        touch($this->tmp.'/refuse');
        $payment = $this->makePayment();
        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);

        $this->postJwt($jwt)->assertOk();

        $this->assertSame('pending', $payment->fresh()->status, 'grant skipped while frozen');
        $event = PaymentWebhookEvent::where('payment_id', $payment->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('breaker_refused', $event->decision);

        $this->assertStringContainsString('guards:money-breaker-alarm', (string) file_get_contents($this->tmp.'/alarm_cmd'));
    }

    /** Missing library = fail-open: grant proceeds exactly as before H4930. */
    public function test_missing_library_is_fail_open(): void
    {
        config(['services.sentinel_breaker.bin' => $this->tmp.'/nope.py']);
        touch($this->tmp.'/refuse');
        $payment = $this->makePayment();
        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);

        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status, 'no library = no freeze, grant proceeds');
        $this->assertSame([], $this->calls());
    }
}
