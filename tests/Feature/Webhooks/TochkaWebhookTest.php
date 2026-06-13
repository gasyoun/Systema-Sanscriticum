<?php

declare(strict_types=1);

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
 * Вебхук Точки — ЕДИНСТВЕННЫЙ автоматический триггер «оплачено -> доступ» в
 * проде (через PaymentObserver), но до сих пор без единого теста. Проверяем
 * отклонение неподписанных/чужих JWT и идемпотентность success-пути.
 *
 * Публичный ключ переопределяется через config (services.tochka.webhook_public_key),
 * что позволяет подписать тестовый JWT встроенной ниже одноразовой RSA-парой.
 * Ключ зафиксирован константой (а не генерируется openssl_pkey_new), чтобы тест
 * работал и на Windows (где генерация требует openssl.cnf), и в CI.
 */
class TochkaWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Одноразовая тестовая RSA-пара (НЕ боевой ключ Точки). */
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

    /** JWK публичной части той же пары (кладём в config вместо боевого ключа Точки). */
    private const TEST_JWK = '{"kty":"RSA","e":"AQAB","n":"vKGoroR6LfYiSN8_sXIeyg4_CFQDyTnZiEGBOCbOYsNRpi9K2KtIX0hVztkKk8MO6l4k39yFwoNvSzaW-lJOEcs_P1fnooUUtLEn4kHO1UPsr_ykuoD25-ID4lvwfLc3wGA3-vRTIAl3ewfSy-sluho4L3jzfRcnPjihTgHq0BRiCBm2IqNJ91Pj_qS4uXXkOr8SYdoAxziMfGAf4BIsp1DCLS2ya83WgJirIRI29xlXph3fjEPf-w7NcECalKF-06kRqfTIqRC5ParvwbvdheRWSHmhlFSQacRV4YjABpbGgPxsFQkLhNP__JMfubGoZXctl9ue9TIMeZx7F50lGQ"}';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function useTestKey(): void
    {
        config(['services.tochka.webhook_public_key' => self::TEST_JWK]);
    }

    private function sign(array $payload): string
    {
        return JWT::encode($payload, self::TEST_PRIVATE_PEM, 'RS256');
    }

    private function postJwt(string $jwt)
    {
        return $this->call('POST', '/api/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'application/jwt'], $jwt);
    }

    /** @test */
    public function garbage_body_is_rejected_with_401(): void
    {
        $this->useTestKey();

        $this->postJwt('not-a-jwt')->assertStatus(401);
    }

    /** @test */
    public function jwt_signed_with_an_unknown_key_is_rejected(): void
    {
        // НЕ переопределяем config -> контроллер берёт боевой ключ Точки, который
        // не соответствует нашей тестовой подписи -> подпись отклоняется.
        $jwt = $this->sign(['purpose' => 'Заказ №1', 'status' => 'paid']);

        $this->postJwt($jwt)->assertStatus(401);
    }

    /** @test */
    public function valid_webhook_without_order_number_is_a_noop(): void
    {
        $this->useTestKey();
        $jwt = $this->sign(['purpose' => 'Пополнение счёта', 'status' => 'paid']);

        $this->postJwt($jwt)->assertOk();
    }

    /** @test */
    public function valid_paid_webhook_marks_payment_paid_and_is_idempotent(): void
    {
        $this->useTestKey();

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'paid']);

        // Первый вебхук: pending -> paid, доступ (группа) выдан.
        $this->postJwt($jwt)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));

        // Второй идентичный вебхук: идемпотентность — статус уже paid, дублей групп нет.
        $this->postJwt($jwt)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(1, $user->fresh()->groups()->count());
    }
}
