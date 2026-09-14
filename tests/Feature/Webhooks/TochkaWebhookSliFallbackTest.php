<?php

declare(strict_types=1);

// [secret-scan-ok] throwaway test-only RSA keypairs (never the real Tochka
// bank key), same convention as tests/Feature/Webhooks/TochkaWebhookTest.php.

namespace Tests\Feature\Webhooks;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4672 — WebhookController::tryDecodeWithSliKey(): the money-axis SLI
 * synthetic-pay probe signs its mocked webhook with a SEPARATE keypair from
 * the real Tochka bank key, so a real bank webhook's verification path is
 * never touched. Proves three things a bug here would be dangerous for:
 *  1. flag OFF (default) → SLI-signed JWT is still rejected 401 (fallback
 *     dormant until a human turns it on).
 *  2. flag ON + SLI key configured → SLI-signed JWT is accepted and grants
 *     access exactly like a real one (same code path past signature check).
 *  3. flag ON but a JWT signed by NEITHER key → still 401 (fallback doesn't
 *     loosen verification to "any key", only adds one specific extra key).
 */
class TochkaWebhookSliFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** Одноразовая тестовая RSA-пара, играющая роль "боевого" ключа Точки. */
    private const BANK_TEST_JWK = '{"kty":"RSA","e":"AQAB","n":"vKGoroR6LfYiSN8_sXIeyg4_CFQDyTnZiEGBOCbOYsNRpi9K2KtIX0hVztkKk8MO6l4k39yFwoNvSzaW-lJOEcs_P1fnooUUtLEn4kHO1UPsr_ykuoD25-ID4lvwfLc3wGA3-vRTIAl3ewfSy-sluho4L3jzfRcnPjihTgHq0BRiCBm2IqNJ91Pj_qS4uXXkOr8SYdoAxziMfGAf4BIsp1DCLS2ya83WgJirIRI29xlXph3fjEPf-w7NcECalKF-06kRqfTIqRC5ParvwbvdheRWSHmhlFSQacRV4YjABpbGgPxsFQkLhNP__JMfubGoZXctl9ue9TIMeZx7F50lGQ"}';

    private const BANK_TEST_PRIVATE_PEM = <<<'PEM'
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

    /**
     * SLI-only одноразовая пара (H4672), отдельная от банковской выше.
     * НЕ боевой ключ Точки, НЕ разделяется с BANK_TEST_*.
     */
    private const SLI_TEST_JWK = '{"kty":"RSA","e":"AQAB","n":"4uDmC1DKI0iPWOpD0HVgt2Lnw2S0D6YSNe18yc11HaNhMDjZAkARQQFXbNipwHK-HB6IhigKZdAI73sZbSwMYxUuNVYYK_kBJw7Uyp9obw9VAPw1ofJCrgJunsWGk0WQW99bO3pZ1njSfKw9t32OMFwF8sRPhwX2aJgizbHCxbdu1nK1MPy9WVJ7pc07MARa3XbxU_NRIcRgH5e9oj5y6JjOafWsoJ3uVQJqsBeFLsPPAhB2Le5MsnSsFCHWguOv70KAyD2YeoLNPYjxrXfZDwNfmadA-VT-kaTnFsgwIj4XtsiZ_xrWIwWpoJgBvZOxheN07OJXBvYpCdhmtPhFpQ"}';

    private const SLI_TEST_PRIVATE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDi4OYLUMojSI9Y
6kPQdWC3YufDZLQPphI17XzJzXUdo2EwONkCQBFBAVds2KnAcr4cHoiGKApl0Ajv
exltLAxjFS41Vhgr+QEnDtTKn2hvD1UA/DWh8kKuAm6exYaTRZBb31s7elnWeNJ8
rD23fY4wXAXyxE+HBfZomCLNscLFt27WcrUw/L1ZUnulzTswBFrddvFT81EhxGAf
l72iPnLomM5p9aygne5VAmqwF4Uuw88CEHYt7kyydKwUIdaC46/vQoDIPZh6gs09
iPGtd9kPA1+Zp0D5VP6RpOcWyDAiPhe2yJn/GtYjBamgmAG9k7GF43Ts4lcG9ikJ
2Ga0+EWlAgMBAAECggEACgbRw9rshAYB58u6oYIpJbxQ7SQMh8suxNlbtCNzXehJ
iZ20NtLpWvZvNwKVm0lhjiaUoVva/HLm4jE43Mf0ktsYcVz+W9B5CjvakEsz4gH1
yhcGBm6JdIfrpvKofDu3zIeg0DkbsqHLqPIybA3iyc+sp5+gusvlqZZc+2WJa77B
Rv3QCSB/3AFmgfALNP9v9P1K385k26RvnpNGm8PuAO1U6omUnbgKGlIMULQTTEid
ixwF9ZKabMMhtrOUVR6TxEzIJnxlwsmTRFIXvNnFogbl4hg7XLyhWquK9InNjldW
ToYF7Lomic78+DukVYmVjoMBR3HWHBPmSILZlX6PYQKBgQDxK8ChjC/y2YtjgbZl
Mif4XpBwFC0Sb1F3DG24Q52ssaNSWTfXL6ykF1eUhQYftIsOrKhdIA5jhA0QJEbR
TNJUuTatwRosuv5jFcfMvP0g834l63KxkXTOhf+rdFBOlrsS3r4hHqKndWvYb/r0
VHX+uMH+ILWAUN8/flfr8a2X7QKBgQDw1CvBLvYgzygCmnrZMwrwhr6u/udU4Q/k
p4v3uY+PIeSdz8MfmfSTe37wrVEYItBhfRJxtpYbgB00IC17pdPCmySA6+zsP1NT
RagYkfKtZqHmepsyXacj4WA0tCrMd22DTzeZP6+MZu6/bxQ1K0+R+LcQWJVaLkQx
mnyNeHk9mQKBgQDo9gSLiGlAwtecdU4FDqABkQcg3Lx1FEazIrRRzC7hBG7pOvlv
ycOQdmPJOX4i3jl9IVc5LZ/4jTQ5JXGq9/QslwS0btWj47WbbQylPuGdFNgENR2D
XShh3pqLuj1gzMVEgxlR0M/5xrk4R2M45OVd+oaZvmrU2knsgVTYu4meOQKBgQDL
Mjm4xeblx+P6Tl1Y5bhVOVuqS2jkNQEz7Cos2mRGYFKE1MfN4hh6V7jDWXkS5Ezt
9JmbWHNOwMnjMUMvELubd0tVe7prmwKzQBKUqJAZvn7b+Jb56AseOwrxbRKvchT0
teIza4iy7iaDXzWtpt18TF4pbJSXgnIHaFGvC/dAAQKBgEDOTA0hSnNGLishJhSI
aSr7LwfEQeEVT64eS0Z9QFu6I4t+MQGEtZPY7YFYKprwiZS3vRNJM/1EcRS0+e/+
Co5QXQbRjjfgDvJl4s5tc90/kb9VCsOJRBkiSdiAS2Jhurqcz1rJsgwyy54HKZ65
JyO2/KocpTluCVHr3WQoA+wI
-----END PRIVATE KEY-----
PEM;

    /** Полностью посторонняя пара — не банк, не SLI. */
    private const UNKNOWN_JWK = '{"kty":"RSA","e":"AQAB","n":"17oo1Iag5GdCPdM-zC9gOdWpYsXAuR4WOoFtpxIEWQ3hShj8srO2K6is3TBni-V6iyoyH77pRp6iLBK2KpTa6XevjvUnqC6HlxXnzXlK4UO0K0jp_1O3rGZj5c7URvPw404Bp9XEFw04mzVFPL6lA8dCv_5uub0BFUbdD_VG3sjIxW2VpTYZweFBvJ8B_ulmx3R360Vyn4TahMO5wj59wseeIc4FL5mMbPeUSEe4QC-ALluwwgAyqn_EumRXCXl9bA_96zXnSVaBCu4IqrfzL6OPdr_F7MrFSx_LWrv_BlmKJONiianAhLlRzeFDU7n4dizee-G43L_gzexy9m_a_w"}';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config(['services.tochka.webhook_public_key' => self::BANK_TEST_JWK]);
        config(['services.tochka.sli_webhook_public_key' => self::SLI_TEST_JWK]);
    }

    private function postJwt(string $jwt)
    {
        return $this->call('POST', '/api/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'application/jwt'], $jwt);
    }

    /**
     * @return array{0: Payment, 1: User, 2: Group}
     */
    private function makePendingPayment(): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G-'.uniqid('', true)]);
        $course->groups()->attach($group->id);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        return [$payment, $user, $group];
    }

    /** @test */
    public function sli_signed_webhook_is_rejected_while_flag_is_off(): void
    {
        config(['features.money_sli_synthetic_pay' => false]);

        [$payment] = $this->makePendingPayment();
        $jwt = JWT::encode(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED'], self::SLI_TEST_PRIVATE_PEM, 'RS256');

        $this->postJwt($jwt)->assertStatus(401);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    /** @test */
    public function sli_signed_webhook_is_accepted_and_grants_access_when_flag_is_on(): void
    {
        config(['features.money_sli_synthetic_pay' => true]);

        [$payment, $user, $group] = $this->makePendingPayment();
        $jwt = JWT::encode([
            'purpose' => "Заказ №{$payment->id}",
            'status' => 'APPROVED',
            'amount' => 0,
            'paymentType' => 'card',
        ], self::SLI_TEST_PRIVATE_PEM, 'RS256');

        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));
    }

    /** @test */
    public function a_real_bank_signed_webhook_still_works_when_the_sli_flag_is_on(): void
    {
        config(['features.money_sli_synthetic_pay' => true]);

        [$payment, $user, $group] = $this->makePendingPayment();
        $jwt = JWT::encode(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED'], self::BANK_TEST_PRIVATE_PEM, 'RS256');

        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));
    }

    /** @test */
    public function an_unknown_key_is_still_rejected_when_the_sli_flag_is_on(): void
    {
        config(['features.money_sli_synthetic_pay' => true]);
        // Ни банковский, ни SLI публичный ключ не совпадают с ключом,
        // которым реально подписано ниже — обе ветки верификации должны отказать.
        config(['services.tochka.webhook_public_key' => self::UNKNOWN_JWK]);
        config(['services.tochka.sli_webhook_public_key' => self::UNKNOWN_JWK]);

        [$payment] = $this->makePendingPayment();
        $jwt = JWT::encode(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED'], self::BANK_TEST_PRIVATE_PEM, 'RS256');

        $this->postJwt($jwt)->assertStatus(401);
        $this->assertSame('pending', $payment->fresh()->status);
    }
}
