<?php

declare(strict_types=1);

namespace Tests\Feature\BookClub;

use App\Models\BookClubOrder;
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
 * Вебхук Tochka для платежа «Книжный клуб» (H8195): доставка оплачивается, но
 * НЕ даёт доступа к курсам (course_id=null, tariff='book_club_delivery' — не
 * ключ доступа; Payment::grantAccess пропускает платёж без курса). Заказ на
 * доставку переживает смену статуса — ручной матчинг с planeta продолжается.
 *
 * Тестовая RSA-пара и механика подписи — зеркало TochkaWebhookTest
 * (одноразовый ключ, константа вместо openssl_pkey_new ради Windows).
 */
class BookClubWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Одноразовая тестовая RSA-пара (НЕ боевой ключ Точки) — как в TochkaWebhookTest. */
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

    /** @return array{0: Payment, 1: BookClubOrder, 2: User} */
    private function bookClubPayment(): array
    {
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => 300,
            'tariff' => 'book_club_delivery',
            'status' => 'pending',
        ]);

        $order = BookClubOrder::create([
            'payment_id' => $payment->id,
            'user_id' => $user->id,
            'planeta_email' => 'backer@mozmail.com',
            'planeta_order_number' => '20241121-4433387',
            'recipient_name' => 'Иванов Иван Иванович',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, д. 1, кв. 1',
            'amount' => 300,
        ]);

        return [$payment, $order, $user];
    }

    public function test_paid_webhook_marks_delivery_payment_paid_without_granting_access(): void
    {
        [$payment, $order, $user] = $this->bookClubPayment();

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'paid']);
        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);

        // Доставка НЕ открывает уроки: групп у студента нет и не появилось.
        $this->assertSame(0, $user->fresh()->groups()->count());

        // Заказ на доставку жив — ручной матчинг с planeta продолжается.
        $this->assertDatabaseHas('book_club_orders', [
            'id' => $order->id,
            'payment_id' => $payment->id,
            'planeta_order_number' => '20241121-4433387',
        ]);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        [$payment, , $user] = $this->bookClubPayment();

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'paid']);
        $this->postJwt($jwt)->assertOk();
        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(0, $user->fresh()->groups()->count());
    }

    public function test_unsigned_webhook_is_rejected(): void
    {
        [$payment] = $this->bookClubPayment();

        $this->postJwt('not-a-jwt')->assertStatus(401);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_delivery_payment_awards_no_prana(): void
    {
        // Живой контур праны: награда за оплату включена явно.
        config(['prana.enabled' => true, 'prana.rewards.payment_success' => 10]);

        // Позитивный контроль: обычный КУРСОВОЙ платёж прану начисляет —
        // механика награды в этом окружении действительно работает.
        $student = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);
        $coursePayment = Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);
        $this->postJwt($this->sign(['purpose' => "Заказ №{$coursePayment->id}", 'status' => 'paid']))->assertOk();

        $this->assertDatabaseCount('prana_transactions', 1);
        $this->assertSame(10, (int) $student->fresh()->prana_balance);

        // Негатив: доставка «Книжного клуба» — не учебный продукт, прану НЕ начисляет
        // (ветка processBookClubDelivery в Payment::fireOnPaid).
        [$payment, , $user] = $this->bookClubPayment();
        $this->postJwt($this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'paid']))->assertOk();

        $this->assertDatabaseCount('prana_transactions', 1); // без новых начислений
        $this->assertSame(0, (int) $user->fresh()->prana_balance);
        $this->assertSame('paid', $payment->fresh()->status);
    }
}
