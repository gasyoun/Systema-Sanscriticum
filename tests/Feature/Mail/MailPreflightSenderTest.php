<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Support\Mail\SenderNameSetting;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 24-11-2026: `mail:preflight` теперь всегда называет отправителя и говорит о
 * расхождении .env с собранным кешем конфига. Предупреждение, не отказ: на проде
 * дрейф лечится следующим deploy, и падающий гейт останавливал бы выкатку из-за
 * состояния, которое сама выкатка исправляет.
 */
class MailPreflightSenderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing/mail-preflight-sender/'.uniqid('', true));
        File::ensureDirectoryExists($this->dir);

        $this->app['env'] = 'production';
        config()->set('mail.mailers.smtp.host', 'smtp.yandex.ru');
        config()->set('mail.from.address', 'rusamskrtam@yandex.ru');
        config()->set('queue.default', 'sync');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function bindSetting(string $envName, ?string $cachedName): void
    {
        $envPath = $this->dir.'/.env';
        $cachePath = $this->dir.'/config.php';

        file_put_contents($envPath, "MAIL_FROM_ADDRESS=rusamskrtam@yandex.ru\nMAIL_FROM_NAME=\"{$envName}\"\n");

        if ($cachedName !== null) {
            $config = ['mail' => ['from' => ['address' => 'rusamskrtam@yandex.ru', 'name' => $cachedName]]];
            file_put_contents($cachePath, '<?php return '.var_export($config, true).';'.PHP_EOL);
        }

        $this->app->instance(SenderNameSetting::class, new SenderNameSetting($envPath, $cachePath));
    }

    /** @test */
    public function it_prints_the_sender_it_would_use(): void
    {
        $this->bindSetting('Общество ревнителей санскрита', null);
        config()->set('mail.from.name', 'Общество ревнителей санскрита');

        $this->artisan('mail:preflight')
            ->assertSuccessful()
            ->expectsOutputToContain('from: Общество ревнителей санскрита <rusamskrtam@yandex.ru>');
    }

    /** @test */
    public function it_warns_when_the_cached_config_is_stale_against_the_env(): void
    {
        $this->bindSetting('Общество ревнителей санскрита', 'Старое имя');
        config()->set('mail.from.name', 'Общество ревнителей санскрита');

        $this->artisan('mail:preflight')
            ->assertSuccessful()
            ->expectsOutputToContain('собранный конфиг использует')
            ->expectsOutputToContain('php artisan config:cache');
    }

    /** @test */
    public function it_stays_silent_about_drift_when_the_cache_agrees(): void
    {
        $this->bindSetting('Общество ревнителей санскрита', 'Общество ревнителей санскрита');
        config()->set('mail.from.name', 'Общество ревнителей санскрита');

        $this->artisan('mail:preflight')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('собранный конфиг использует');
    }
}
