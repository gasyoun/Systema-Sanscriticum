<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Telegram\MadelineClientFactory;
use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TelegramZapisiRosterTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = storage_path('framework/testing/roster-'.uniqid());
        config(['services.telegram_harvest.store_path' => $this->store]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    private function fakeFactory(object $client): MadelineClientFactory
    {
        $factory = new class($client) extends MadelineClientFactory
        {
            public function __construct(private object $fake) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function open(?string $clientClass = null): object
            {
                return $this->fake;
            }
        };
        $this->app->instance(MadelineClientFactory::class, $factory);

        return $factory;
    }

    public function test_fetch_roster_normalizes_participants(): void
    {
        $client = new class
        {
            public function getPwrChat(int|string $peer, bool $fullFetch = true, bool $send = true): array
            {
                return [
                    'participants' => [
                        ['user' => ['id' => 1, 'username' => 'ivan', 'first_name' => 'Иван', 'last_name' => 'Петров']],
                        ['user' => ['id' => 2, 'first_name' => 'Zapisi', 'bot' => true]],
                    ],
                ];
            }
        };
        $this->fakeFactory($client);

        $roster = app(TelegramHarvestSyncService::class)->fetchRoster('-1009988');

        $this->assertCount(2, $roster);
        $this->assertSame(1, $roster[0]['id']);
        $this->assertSame('ivan', $roster[0]['username']);
        $this->assertSame('Иван Петров', $roster[0]['name']);
        $this->assertFalse($roster[0]['is_bot']);
        $this->assertTrue($roster[1]['is_bot']);
    }

    public function test_fetch_roster_returns_empty_when_not_configured(): void
    {
        // Force isConfigured()=false explicitly — never rely on ambient .env
        // api_id/api_hash being absent, or this hits a REAL MadelineProto
        // client (see Systema-Sanscriticum memory: real credentials can be
        // present in a local .env even when TELEGRAM_SUPPORT_ENABLED=false,
        // since MadelineClientFactory::isConfigured() does not check the
        // enabled flag).
        config(['services.telegram_support.api_id' => null, 'services.telegram_support.api_hash' => null]);

        $roster = app(TelegramHarvestSyncService::class)->fetchRoster('-1009988');
        $this->assertSame([], $roster);
    }

    public function test_command_writes_roster_snapshot(): void
    {
        $client = new class
        {
            public function getPwrChat(int|string $peer, bool $fullFetch = true, bool $send = true): array
            {
                return ['participants' => [
                    ['user' => ['id' => 7, 'username' => 'anya', 'first_name' => 'Аня']],
                ]];
            }
        };
        $this->fakeFactory($client);

        $this->artisan('telegram-harvest:roster', ['peer' => '-1009988'])->assertExitCode(0);

        $file = $this->store.'/roster/-1009988.json';
        $this->assertFileExists($file);
        $decoded = json_decode(File::get($file), true);
        $this->assertSame(1, $decoded['count']);
        $this->assertSame('anya', $decoded['members'][0]['username']);
    }
}
