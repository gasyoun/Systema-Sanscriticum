<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DownloadTelegramZapisiMedia;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DownloadTelegramZapisiMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = storage_path('framework/testing/zapisi-media-'.uniqid());
        config(['services.telegram_harvest.store_path' => $this->store]);
        MarketingSetting::create(['zapisi_bot_token' => 'fake-token']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    public function test_downloads_file_via_get_file_and_stores_it(): void
    {
        Http::fake([
            'api.telegram.org/botfake-token/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']], 200),
            'api.telegram.org/file/botfake-token/*' => Http::response('binary-image-bytes', 200),
        ]);

        (new DownloadTelegramZapisiMedia(-1009988, 502, 'large_id', 'photo', '2026-07-01'))->handle();

        $file = $this->store.'/zapisi_media/-1009988/2026-07-01/502_photo.jpg';
        $this->assertFileExists($file);
        $this->assertSame('binary-image-bytes', File::get($file));
    }

    public function test_no_token_is_a_noop(): void
    {
        MarketingSetting::query()->update(['zapisi_bot_token' => null]);
        MarketingSetting::flushCached();

        Http::fake();

        (new DownloadTelegramZapisiMedia(-1009988, 502, 'large_id', 'photo', '2026-07-01'))->handle();

        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist($this->store.'/zapisi_media');
    }

    public function test_get_file_failure_does_not_throw(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false], 400),
        ]);

        (new DownloadTelegramZapisiMedia(-1009988, 502, 'large_id', 'photo', '2026-07-01'))->handle();

        $this->assertDirectoryDoesNotExist($this->store.'/zapisi_media');
    }
}
