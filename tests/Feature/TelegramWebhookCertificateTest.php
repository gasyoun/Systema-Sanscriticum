<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Загрузка сертификата входного узла при setWebhook.
 *
 * Узел (зарубежный nginx, принимающий апдейты вместо прода) работает на
 * самоподписанном сертификате — Telegram примет такой вебхук только вместе с
 * загруженным файлом. Регистрация без него даёт вебхук, который выглядит живым
 * и молча ничего не доставляет.
 */
class TelegramWebhookCertificateTest extends TestCase
{
    use RefreshDatabase;

    private string $certPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certPath = storage_path('framework/testing/tg-webhook-'.uniqid().'.crt');
        File::ensureDirectoryExists(dirname($this->certPath));
        File::put($this->certPath, "-----BEGIN CERTIFICATE-----\nMIIB-test-fixture\n-----END CERTIFICATE-----\n");

        MarketingSetting::create([
            'zapisi_bot_username' => 'zapisi_ORSbot',
            'zapisi_bot_token' => 'ZAPISI-TOKEN',
            'zapisi_webhook_secret' => 'zapisi-secret',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    }

    protected function tearDown(): void
    {
        File::delete($this->certPath);
        parent::tearDown();
    }

    public function test_certificate_is_attached_as_multipart(): void
    {
        config([
            'services.telegram_webhook.base_url' => 'https://103.112.71.201',
            'services.telegram_webhook.certificate' => $this->certPath,
        ]);

        $this->artisan('zapisi:set-webhook')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->isMultipart() && $request->hasFile('certificate'));
    }

    /**
     * В multipart Laravel разворачивает массив в части `allowed_updates[]`, а
     * Bot API их не разбирает — нужна JSON-строка. Без этого вебхук встанет, но
     * с пустым набором типов апдейтов.
     */
    public function test_allowed_updates_are_json_encoded_in_multipart(): void
    {
        config([
            'services.telegram_webhook.base_url' => 'https://103.112.71.201',
            'services.telegram_webhook.certificate' => $this->certPath,
        ]);

        $this->artisan('zapisi:set-webhook')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            foreach ($request->data() as $part) {
                if (($part['name'] ?? '') === 'allowed_updates') {
                    return $part['contents'] === '["message","channel_post"]';
                }
            }

            return false;
        });
    }

    /**
     * Сертификат принадлежит входному узлу. Если узла нет и регистрируемся на
     * app.url с обычным публичным сертификатом, приложенный самоподписанный файл
     * сломал бы доставку — поэтому он не прикладывается, даже когда путь задан.
     */
    public function test_no_certificate_when_entry_node_is_not_configured(): void
    {
        config([
            'services.telegram_webhook.base_url' => null,
            'services.telegram_webhook.certificate' => $this->certPath,
        ]);

        $this->artisan('zapisi:set-webhook')->assertSuccessful();

        Http::assertSent(fn (Request $request) => ! $request->isMultipart());
    }

    /** Нечитаемый сертификат — громкая ошибка, а не тихая регистрация без него. */
    public function test_unreadable_certificate_fails_loudly(): void
    {
        config([
            'services.telegram_webhook.base_url' => 'https://103.112.71.201',
            'services.telegram_webhook.certificate' => '/nope/missing.crt',
        ]);

        $this->expectExceptionMessageMatches('/Сертификат входного узла недоступен/');

        $this->artisan('zapisi:set-webhook');
    }
}
