<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Jobs\IssueDigitalKassaReceiptJob;
use App\Models\Course;
use App\Models\FiscalReceipt;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\Fiscal\DigitalKassaClient;
use App\Services\Fiscal\DigitalKassaReceiptBuilder;
use App\Support\MoneySli\MoneySliAlerter;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Переезд фискализации: эквайринг Точки + чек Digital Kassa после оплаты
 * (features.digitalkassa_receipts). Ключевые инварианты:
 *  - флаг OFF — байт-в-байт прежний /payments_with_receipt;
 *  - флаг ON — /payments без Items/Client, провайдер зафиксирован на платеже;
 *  - чек DK только для платежей fiscal_provider=digitalkassa (без двойной фискализации);
 *  - ретрай 5xx — тот же receipt_id (идемпотентность DK); 400 — failed.
 */
class DigitalKassaReceiptTest extends TestCase
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

    /** JWK публичной части той же тестовой пары (как в TochkaWebhookTest). */
    private const TEST_JWK = '{"kty":"RSA","e":"AQAB","n":"vKGoroR6LfYiSN8_sXIeyg4_CFQDyTnZiEGBOCbOYsNRpi9K2KtIX0hVztkKk8MO6l4k39yFwoNvSzaW-lJOEcs_P1fnooUUtLEn4kHO1UPsr_ykuoD25-ID4lvwfLc3wGA3-vRTIAl3ewfSy-sluho4L3jzfRcnPjihTgHq0BRiCBm2IqNJ91Pj_qS4uXXkOr8SYdoAxziMfGAf4BIsp1DCLS2ya83WgJirIRI29xlXph3fjEPf-w7NcECalKF-06kRqfTIqRC5ParvwbvdheRWSHmhlFSQacRV4YjABpbGgPxsFQkLhNP__JMfubGoZXctl9ue9TIMeZx7F50lGQ"}';

    private const DK = 'api.digitalkassa.ru/*';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        config([
            'services.digitalkassa.actor_id' => '1234567',
            'services.digitalkassa.actor_token' => 'secret-token',
            'services.digitalkassa.c_group_id' => '42',
            'services.digitalkassa.taxation' => 2,
            'services.digitalkassa.vat' => 6,
            'services.digitalkassa.billing_place' => 'https://samskrte.ru/',
            'money_sli.telegram_chat_id' => '',
        ]);
    }

    // ---------- создание ссылки ----------

    /** @test */
    public function flag_off_keeps_tochka_fiscal_link_and_marks_provider_tochka(): void
    {
        config(['features.digitalkassa_receipts' => false]);
        $payment = $this->createDepositViaCheckout();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/payments_with_receipt')
            && isset($r->data()['Data']['Items']));
        $this->assertSame('tochka', $payment->fiscal_provider);
        $this->assertNull($payment->fiscalReceipt);
    }

    /** @test */
    public function flag_on_creates_non_fiscal_link_and_pending_dk_receipt(): void
    {
        config([
            'features.digitalkassa_receipts' => true,
            'services.tochka.tax_system_code' => 'usn_income',
        ]);
        $payment = $this->createDepositViaCheckout();

        Http::assertSent(function (Request $r) {
            $data = $r->data()['Data'] ?? [];

            return str_ends_with($r->url(), '/payments')
                && ! isset($data['Items'])
                && ! isset($data['Client'])
                && ! isset($data['taxSystemCode'])
                && (float) $data['amount'] === 1500.0;
        });

        $this->assertSame(FiscalReceipt::PROVIDER_DIGITALKASSA, $payment->fiscal_provider);
        $receipt = $payment->fiscalReceipt;
        $this->assertSame(FiscalReceipt::STATUS_PENDING, $receipt->status);
        $this->assertSame('Бронь курса «Тестовый курс»', $receipt->item_name);
        // Бронь у Точки была full_prepayment → тег 1214 = 1 (паритет 1:1).
        $this->assertSame(FiscalReceipt::METHOD_FULL_PREPAYMENT, $receipt->payment_method);
    }

    // ---------- вебхук ----------

    /** @test */
    public function paid_webhook_dispatches_receipt_job_once_for_dk_payment(): void
    {
        Queue::fake();
        $payment = $this->dkPayment();

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);
        $this->postJwt($jwt)->assertOk();
        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        Queue::assertPushed(IssueDigitalKassaReceiptJob::class, 1);
        Queue::assertPushed(IssueDigitalKassaReceiptJob::class, fn ($job) => $job->paymentId === $payment->id);
    }

    /**
     * Ссылка создана при флаге OFF (чек пробивает Точка), флаг включили до вебхука —
     * второго чека от DK быть не должно.
     *
     * @test
     */
    public function tochka_fiscalised_payment_gets_no_dk_receipt_even_if_flag_flipped(): void
    {
        Queue::fake();
        config(['features.digitalkassa_receipts' => true]);
        $payment = $this->coursePayment(['fiscal_provider' => 'tochka']);

        $this->postJwt($this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']))->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        Queue::assertNotPushed(IssueDigitalKassaReceiptJob::class);
    }

    // ---------- джоба ----------

    /** @test */
    public function job_issues_income_receipt_and_stores_fiscal_data(): void
    {
        $payment = $this->dkPayment(['status' => 'paid']);
        Http::fake([self::DK => Http::response($this->okPayload(), 201)]);

        $this->runJob($payment);

        $receipt = $payment->fiscalReceipt()->first();
        $this->assertSame(FiscalReceipt::STATUS_DONE, $receipt->status);
        $this->assertSame(1111111111, $receipt->fiscal_num);
        $this->assertSame('https://dk.example/r/abc', $receipt->receipt_url);

        Http::assertSent(function (Request $r) use ($payment) {
            $b = $r->data();
            $item = $b['items'][0];

            return $r->method() === 'POST'
                && str_ends_with($r->url(), "/v2.1/c_groups/42/receipts/ss-{$payment->id}-1")
                && $r->header('Authorization')[0] === 'Basic '.base64_encode('1234567:secret-token')
                && $b['type'] === 1
                && $b['taxation'] === 2
                && $b['is_internet'] === 1
                && $b['amount'] === ['cashless' => 4800.0]
                && $b['notify'] === ['emails' => [$payment->user->email]]
                && $b['loc'] === ['billing_place' => 'https://samskrte.ru/']
                && $item['type'] === 4
                && $item['payment_method'] === 4
                && $item['price'] === 4800.0 && $item['amount'] === 4800.0 && $item['quantity'] === 1
                && $item['vat'] === 6
                && $item['name'] === 'Курс — Полный';
        });
    }

    /** @test */
    public function accepted_202_polls_status_on_next_run(): void
    {
        $payment = $this->dkPayment(['status' => 'paid']);
        Http::fakeSequence(self::DK)
            ->push('', 202)
            ->push($this->okPayload(), 200);

        $this->runJob($payment);
        $this->assertSame(FiscalReceipt::STATUS_PROCESSING, $payment->fiscalReceipt()->first()->status);

        $this->runJob($payment);
        $this->assertSame(FiscalReceipt::STATUS_DONE, $payment->fiscalReceipt()->first()->status);

        $recorded = Http::recorded();
        $this->assertSame('GET', $recorded[1][0]->method());
        $this->assertSame($recorded[0][0]->url(), $recorded[1][0]->url());
    }

    /** @test */
    public function server_error_is_retried_with_the_same_receipt_id(): void
    {
        $payment = $this->dkPayment(['status' => 'paid']);
        Http::fakeSequence(self::DK)
            ->push('oops', 503)
            ->push($this->okPayload(), 201);

        try {
            $this->runJob($payment);
            $this->fail('503 должен бросить исключение для ретрая очереди');
        } catch (RuntimeException) {
        }
        $this->assertSame(FiscalReceipt::STATUS_PENDING, $payment->fiscalReceipt()->first()->status);

        $this->runJob($payment);

        $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertCount(2, $urls);
        $this->assertSame($urls[0], $urls[1]);
        $this->assertSame(FiscalReceipt::STATUS_DONE, $payment->fiscalReceipt()->first()->status);
    }

    /** @test */
    public function validation_error_marks_receipt_failed_without_touching_payment(): void
    {
        $payment = $this->dkPayment(['status' => 'paid']);
        Http::fake([self::DK => Http::response([['type' => 'BAD_VALUE', 'desc' => 'x', 'path' => '$.items']], 400)]);

        $this->runJob($payment);

        $receipt = $payment->fiscalReceipt()->first();
        $this->assertSame(FiscalReceipt::STATUS_FAILED, $receipt->status);
        $this->assertStringContainsString('BAD_VALUE', $receipt->last_error);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    /** @test */
    public function falls_back_to_e164_phone_when_email_is_unusable(): void
    {
        $payment = $this->dkPayment(['status' => 'paid'], ['email' => 'no-email', 'phone' => '8 (916) 123-45-67']);
        Http::fake([self::DK => Http::response($this->okPayload(), 201)]);

        $this->runJob($payment);

        Http::assertSent(fn (Request $r) => $r->data()['notify'] === ['phone' => '+79161234567']);
    }

    /** @test */
    public function done_receipt_is_never_sent_again(): void
    {
        $payment = $this->dkPayment(['status' => 'paid']);
        $payment->fiscalReceipt->update(['status' => FiscalReceipt::STATUS_DONE]);
        Http::fake();

        $this->runJob($payment);

        Http::assertNothingSent();
    }

    // ---------- команда повтора ----------

    /** @test */
    public function retry_command_with_new_id_bumps_attempt_and_requeues(): void
    {
        Queue::fake();
        $payment = $this->dkPayment(['status' => 'paid']);
        $payment->fiscalReceipt->update(['status' => FiscalReceipt::STATUS_FAILED]);

        $this->artisan('fiscal:retry-digitalkassa', ['--payment' => $payment->id, '--new-id' => true])->assertSuccessful();

        $receipt = $payment->fiscalReceipt()->first();
        $this->assertSame(FiscalReceipt::STATUS_PENDING, $receipt->status);
        $this->assertSame("ss-{$payment->id}-2", $receipt->receiptId());
        Queue::assertPushed(IssueDigitalKassaReceiptJob::class, 1);
    }

    /** @test */
    public function sweep_requeues_only_stale_receipts_of_paid_payments(): void
    {
        Queue::fake();
        $stale = $this->dkPayment(['status' => 'paid']);
        $unpaid = $this->dkPayment(['status' => 'pending']);
        FiscalReceipt::query()->update(['updated_at' => now()->subHours(3)]);
        $fresh = $this->dkPayment(['status' => 'paid']);

        $this->artisan('fiscal:retry-digitalkassa')->assertSuccessful();

        Queue::assertPushed(IssueDigitalKassaReceiptJob::class, 1);
        Queue::assertPushed(IssueDigitalKassaReceiptJob::class, fn ($job) => $job->paymentId === $stale->id);
    }

    // ---------- helpers ----------

    private function createDepositViaCheckout(): Payment
    {
        MarketingSetting::create(['deposit_enabled' => true]);
        $course = Course::factory()->create(['deposit_amount' => 1500, 'title' => 'Тестовый курс']);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/xyz', 'paymentLinkId' => 'tx_1'],
            ], 200),
        ]);

        $this->post(route('deposit.create', $course->slug), [
            'name' => 'Пётр',
            'email' => 'petr@example.test',
        ])->assertRedirect('https://pay.tochka.com/redirect/xyz');

        return Payment::query()->where('tariff', 'deposit')->latest('id')->firstOrFail();
    }

    private function coursePayment(array $attrs = [], array $userAttrs = []): Payment
    {
        $user = User::factory()->create($userAttrs);
        $course = Course::factory()->create();
        $course->groups()->attach(Group::create(['name' => 'G'.uniqid()])->id);

        return Payment::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ], $attrs));
    }

    private function dkPayment(array $attrs = [], array $userAttrs = []): Payment
    {
        $payment = $this->coursePayment(['fiscal_provider' => FiscalReceipt::PROVIDER_DIGITALKASSA] + $attrs, $userAttrs);
        FiscalReceipt::create([
            'payment_id' => $payment->id,
            'provider' => FiscalReceipt::PROVIDER_DIGITALKASSA,
            'status' => FiscalReceipt::STATUS_PENDING,
            'item_name' => 'Курс — Полный',
            'payment_method' => FiscalReceipt::METHOD_FULL_PAYMENT,
        ]);

        return $payment->fresh();
    }

    private function runJob(Payment $payment): void
    {
        (new IssueDigitalKassaReceiptJob($payment->id))->handle(
            app(DigitalKassaClient::class),
            app(DigitalKassaReceiptBuilder::class),
            app(MoneySliAlerter::class),
        );
    }

    private function okPayload(): array
    {
        return [
            'doc' => ['reg_time' => '2026-09-29T12:00:00', 'shift_num' => 1, 'index' => 1, 'fiscal_sign' => 2, 'fiscal_num' => 1111111111],
            'cashbox' => ['rn' => '000444444444444'],
            'service' => ['receipt_url' => 'https://dk.example/r/abc'],
        ];
    }

    private function sign(array $payload): string
    {
        config(['services.tochka.webhook_public_key' => self::TEST_JWK]);

        return JWT::encode($payload, self::TEST_PRIVATE_PEM, 'RS256');
    }

    private function postJwt(string $jwt)
    {
        return $this->call('POST', '/api/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'application/jwt'], $jwt);
    }
}
