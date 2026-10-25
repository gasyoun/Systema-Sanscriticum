<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\Payments\HttpPaypalWebhookSignatureVerifier;
use App\Services\Payments\PaypalSubscriptionsService;
use App\Services\Payments\PaypalWebhookSignatureVerifier;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * H6395 lever 2 (metric M9, GTD row WHE) — live PayPal verify-webhook-signature.
 *
 * До этого класса `HttpPaypalWebhookSignatureVerifier` держал 3/43 statements
 * (7 %) на money-матрице, тогда как соседние платёжные классы — 86–98 %.
 * Класс — ДЕФОЛТНЫЙ биндинг `PaypalWebhookSignatureVerifier` в
 * AppServiceProvider, то есть это и есть продовый гейт доверия вебхука:
 * неверная ветка здесь либо отклоняет реальные платежи, либо принимает
 * поддельные.
 *
 * Зафиксированный контракт:
 *  - skip-флаг (только тест/локально) закорачивает в TRUE без сети;
 *  - любой отсутствующий креденшл (webhook_id/client_id/client_secret) ⇒ FALSE без сети;
 *  - провал OAuth / ответ без токена / не-string / пустой токен ⇒ FALSE;
 *  - не-2xx от verify-эндпоинта ⇒ FALSE (+ warning в лог);
 *  - любой verification_status кроме SUCCESS ⇒ FALSE, SUCCESS регистронезависим;
 *  - заголовки PayPal и декодированное событие уходят в PayPal дословно;
 *  - битый JSON тела деградирует в [] (никогда не фатал);
 *  - base_url-override и выбор live/sandbox-хоста управляют ОБОИМИ вызовами.
 *
 * Каждый тест фейкает HTTP — реальный эквайринг не трогается никогда.
 */
final class HttpPaypalWebhookSignatureVerifierTest extends TestCase
{
    private const SANDBOX_TOKEN_URL = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';

    private const SANDBOX_VERIFY_URL = 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature';

    private const WEBHOOK_EVENT = '{"id":"WH-EVT-1","event_type":"PAYMENT.SALE.COMPLETED","resource":{"id":"I-ABC"}}';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configure();
    }

    /** Герметичная конфигурация канала: локальный .env не должен влиять. */
    private function configure(array $overrides = []): void
    {
        Config::set('services.paypal.subscriptions.skip_signature_verify', false);
        Config::set('services.paypal.subscriptions.webhook_id', 'WH-TEST-1');
        Config::set('services.paypal.subscriptions.client_id', 'client-test');
        Config::set('services.paypal.subscriptions.client_secret', 'secret-test');
        Config::set('services.paypal.subscriptions.mode', 'sandbox');
        Config::set('services.paypal.subscriptions.base_url', '');

        foreach ($overrides as $key => $value) {
            Config::set('services.paypal.subscriptions.'.$key, $value);
        }
    }

    private function verifier(): HttpPaypalWebhookSignatureVerifier
    {
        return new HttpPaypalWebhookSignatureVerifier(new PaypalSubscriptionsService());
    }

    private function webhookRequest(string $body = self::WEBHOOK_EVENT): Request
    {
        return Request::create(
            uri: '/webhooks/paypal/subscriptions',
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
                'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/cert.pem',
                'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
                'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig-1',
                'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-25T00:00:00Z',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    /** Фейк happy-path PayPal: токен, затем verify с заданным вердиктом. */
    private function fakePaypal(
        array $verifyBody = ['verification_status' => 'SUCCESS'],
        int $verifyStatus = 200,
        int $tokenStatus = 200,
        array $tokenBody = ['access_token' => 'A21AA-token'],
    ): void {
        Http::fake([
            self::SANDBOX_TOKEN_URL => Http::response($tokenBody, $tokenStatus),
            self::SANDBOX_VERIFY_URL => Http::response($verifyBody, $verifyStatus),
        ]);
    }

    // ---------------------------------------------------------------- binding

    public function test_interface_defaults_to_the_http_verifier_in_the_container(): void
    {
        // money-матрица меряет именно этот класс, потому что прод резолвит его
        // через интерфейс; если биндинг подменят, тесты должны это поймать.
        $this->assertInstanceOf(
            HttpPaypalWebhookSignatureVerifier::class,
            $this->app->make(PaypalWebhookSignatureVerifier::class),
        );
    }

    // ------------------------------------------------------- short circuits

    public function test_skip_flag_short_circuits_to_true_without_any_network(): void
    {
        $this->configure(['skip_signature_verify' => true]);
        Http::fake();

        $this->assertTrue($this->verifier()->verify($this->webhookRequest()));

        Http::assertNothingSent();
    }

    public function test_missing_webhook_id_rejects_without_network(): void
    {
        $this->configure(['webhook_id' => '']);
        Http::fake();

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Http::assertNothingSent();
    }

    public function test_missing_client_id_rejects_without_network(): void
    {
        $this->configure(['client_id' => '']);
        Http::fake();

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Http::assertNothingSent();
    }

    public function test_missing_client_secret_rejects_without_network(): void
    {
        $this->configure(['client_secret' => '']);
        Http::fake();

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------- oauth leg

    public function test_oauth_http_failure_rejects_and_logs_the_status(): void
    {
        Log::spy();
        $this->fakePaypal(tokenStatus: 401, tokenBody: ['error' => 'invalid_client']);

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Log::shouldHaveReceived('warning')
            ->with('paypal_subscriptions.oauth_failed', ['status' => 401])
            ->once();

        // Без токена verify-эндпоинт не дёргается вообще.
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::SANDBOX_TOKEN_URL);
    }

    public function test_oauth_response_without_access_token_rejects(): void
    {
        Http::fake([
            self::SANDBOX_TOKEN_URL => Http::response(['token_type' => 'Bearer'], 200),
            self::SANDBOX_VERIFY_URL => Http::response(['verification_status' => 'SUCCESS'], 200),
        ]);

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Http::assertSentCount(1);
    }

    public function test_oauth_empty_access_token_rejects(): void
    {
        $this->fakePaypal(tokenBody: ['access_token' => '']);

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Http::assertSentCount(1);
    }

    public function test_oauth_non_string_access_token_rejects(): void
    {
        $this->fakePaypal(tokenBody: ['access_token' => ['not-a-string']]);

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Http::assertSentCount(1);
    }

    public function test_oauth_request_uses_client_credentials_grant_and_basic_auth(): void
    {
        $this->fakePaypal();

        $this->verifier()->verify($this->webhookRequest());

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== self::SANDBOX_TOKEN_URL) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Basic '.base64_encode('client-test:secret-test'))
                && ($request->data()['grant_type'] ?? null) === 'client_credentials';
        });
    }

    // ------------------------------------------------------------- verify leg

    public function test_http_failure_on_verify_rejects_and_logs_the_status(): void
    {
        Log::spy();
        $this->fakePaypal(verifyBody: ['name' => 'INVALID_REQUEST'], verifyStatus: 422);

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));

        Log::shouldHaveReceived('warning')
            ->with('paypal_subscriptions.webhook.verify_http_failed', ['status' => 422])
            ->once();
    }

    public function test_verification_status_success_returns_true(): void
    {
        $this->fakePaypal(verifyBody: ['verification_status' => 'SUCCESS']);

        $this->assertTrue($this->verifier()->verify($this->webhookRequest()));
    }

    public function test_verification_status_success_is_case_insensitive(): void
    {
        $this->fakePaypal(verifyBody: ['verification_status' => 'success']);

        $this->assertTrue($this->verifier()->verify($this->webhookRequest()));
    }

    public function test_any_other_verification_status_rejects(): void
    {
        foreach (['FAILURE', 'PENDING', 'success-ish', ''] as $status) {
            $this->fakePaypal(verifyBody: ['verification_status' => $status]);

            $this->assertFalse(
                $this->verifier()->verify($this->webhookRequest()),
                'verification_status "'.$status.'" must not be accepted as a verified webhook',
            );
        }
    }

    public function test_missing_verification_status_rejects(): void
    {
        $this->fakePaypal(verifyBody: ['unexpected' => 'shape']);

        $this->assertFalse($this->verifier()->verify($this->webhookRequest()));
    }

    // ---------------------------------------------------------------- payload

    public function test_verify_payload_carries_headers_webhook_id_and_decoded_event(): void
    {
        $this->fakePaypal();

        $this->assertTrue($this->verifier()->verify($this->webhookRequest()));

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== self::SANDBOX_VERIFY_URL) {
                return false;
            }

            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer A21AA-token')
                && $data['auth_algo'] === 'SHA256withRSA'
                && $data['cert_url'] === 'https://api.paypal.com/cert.pem'
                && $data['transmission_id'] === 'tx-1'
                && $data['transmission_sig'] === 'sig-1'
                && $data['transmission_time'] === '2026-10-25T00:00:00Z'
                && $data['webhook_id'] === 'WH-TEST-1'
                && $data['webhook_event'] === [
                    'id' => 'WH-EVT-1',
                    'event_type' => 'PAYMENT.SALE.COMPLETED',
                    'resource' => ['id' => 'I-ABC'],
                ];
        });
    }

    public function test_malformed_json_body_degrades_to_empty_event_without_fatal(): void
    {
        $this->fakePaypal();

        $this->assertTrue($this->verifier()->verify($this->webhookRequest('{not-json')));

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === self::SANDBOX_VERIFY_URL
                && $request->data()['webhook_event'] === [];
        });
    }

    // ------------------------------------------------------------------ hosts

    public function test_base_url_override_drives_both_calls(): void
    {
        $this->configure(['base_url' => 'https://api-m.example.test/']);

        Http::fake([
            'https://api-m.example.test/v1/oauth2/token' => Http::response(['access_token' => 'A21AA-token'], 200),
            'https://api-m.example.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
        ]);

        $this->assertTrue($this->verifier()->verify($this->webhookRequest()));

        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://api-m.example.test/v1/'));
        Http::assertSentCount(2);
    }

    public function test_live_mode_uses_the_production_paypal_host(): void
    {
        $this->configure(['mode' => 'live']);

        Http::fake([
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21AA-token'], 200),
            'https://api-m.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
        ]);

        $this->assertTrue($this->verifier()->verify($this->webhookRequest()));

        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://api-m.paypal.com/v1/'));
        Http::assertSentCount(2);
    }
}
