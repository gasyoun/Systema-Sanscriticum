<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Group;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * telegram-harvest:roster-groups — юзербот снимает ростер каждой учебной группы
 * с telegram_chat_id в roster/{chat_id}.json (ключ дашборда). Плюс регресс на
 * фикс «peer not present»: прайминг getDialogIds ПЕРЕД getPwrChat.
 */
class SnapshotGroupRostersTest extends TestCase
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

    private function fakeFactory(object $client): void
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
    }

    private function enable(): void
    {
        config([
            'services.telegram_harvest.enabled' => true,
            'services.telegram_support.enabled' => true,
        ]);
    }

    private function rosterClient(): object
    {
        return new class
        {
            public function getDialogIds(): array
            {
                return [];
            }

            public function getPwrChat(int|string $peer, bool $fullFetch, bool $send): array
            {
                return [
                    'photo' => ['_' => 'photo', 'id' => 1],
                    'participants' => [
                        ['user' => ['id' => 1, 'username' => 'u'.$peer, 'first_name' => 'U']],
                    ],
                ];
            }

            public function downloadToFile(mixed $media, string $path): string
            {
                File::put($path, 'JPEGBYTES');

                return $path;
            }
        };
    }

    public function test_snapshots_only_active_and_forming_groups_with_chat_id(): void
    {
        $this->enable();
        $this->fakeFactory($this->rosterClient());

        Group::create(['name' => 'Актив', 'telegram_chat_id' => '-100111', 'status' => 'active']);
        Group::create(['name' => 'Набор', 'telegram_chat_id' => '-100222', 'status' => 'forming']);
        Group::create(['name' => 'Архив', 'telegram_chat_id' => '-100333', 'status' => 'archived']);
        Group::create(['name' => 'Без чата', 'status' => 'active']); // нет telegram_chat_id

        $this->artisan('telegram-harvest:roster-groups')->assertExitCode(0);

        $this->assertFileExists($this->store.'/roster/-100111.json');
        $this->assertFileExists($this->store.'/roster/-100222.json');
        $this->assertFileDoesNotExist($this->store.'/roster/-100333.json'); // архив не снимаем

        $decoded = json_decode(File::get($this->store.'/roster/-100111.json'), true);
        $this->assertSame(1, $decoded['count']);

        // Аватар чата скачан рядом с ростером.
        $this->assertFileExists($this->store.'/roster/avatars/-100111.jpg');
        $this->assertSame('JPEGBYTES', File::get($this->store.'/roster/avatars/-100111.jpg'));
    }

    public function test_noop_when_harvest_disabled(): void
    {
        config(['services.telegram_harvest.enabled' => false, 'services.telegram_support.enabled' => true]);
        $this->fakeFactory($this->rosterClient());
        Group::create(['name' => 'Актив', 'telegram_chat_id' => '-100111', 'status' => 'active']);

        $this->artisan('telegram-harvest:roster-groups')->assertExitCode(0);

        $this->assertFileDoesNotExist($this->store.'/roster/-100111.json');
    }

    public function test_session_busy_writes_nothing(): void
    {
        $this->enable();
        $this->fakeFactory($this->rosterClient());
        Group::create(['name' => 'Актив', 'telegram_chat_id' => '-100111', 'status' => 'active']);

        // Замок держит «другая команда» → withMadelineSessionLock вернёт null.
        Cache::lock('madeline-session', 30)->get();

        $this->artisan('telegram-harvest:roster-groups')->assertExitCode(0);

        $this->assertFileDoesNotExist($this->store.'/roster/-100111.json');
    }

    public function test_fetch_roster_primes_peer_db_before_pwrchat(): void
    {
        // Регресс на «peer not present»: getPwrChat падает, пока не вызван
        // getDialogIds (прайминг). fetchRoster должен праймить ПЕРЕД getPwrChat.
        $client = new class
        {
            private bool $primed = false;

            public function getDialogIds(): array
            {
                $this->primed = true;

                return [];
            }

            public function getPwrChat(int|string $peer, bool $fullFetch, bool $send): array
            {
                if (! $this->primed) {
                    throw new \RuntimeException('This peer is not present in the internal peer database');
                }

                return ['participants' => [['user' => ['id' => 5, 'first_name' => 'X']]]];
            }
        };
        $this->fakeFactory($client);

        $roster = app(TelegramHarvestSyncService::class)->fetchRoster('-100111');

        $this->assertCount(1, $roster);
        $this->assertSame(5, $roster[0]['id']);
    }

    public function test_fetch_roster_returns_empty_when_pwrchat_always_throws(): void
    {
        // Аккаунт не в чате: getPwrChat падает даже после прайминга → [] (не крашим батч).
        $client = new class
        {
            public function getDialogIds(): array
            {
                return [];
            }

            public function getPwrChat(int|string $peer, bool $fullFetch, bool $send): array
            {
                throw new \RuntimeException('This peer is not present in the internal peer database');
            }
        };
        $this->fakeFactory($client);

        $this->assertSame([], app(TelegramHarvestSyncService::class)->fetchRoster('-100111'));
    }
}
