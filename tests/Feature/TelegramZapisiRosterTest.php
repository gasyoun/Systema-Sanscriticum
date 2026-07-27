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

    /**
     * Регрессия на жалобу 27.07.2026: дашборд показывал 15 участников при 11
     * реальных. Для супергруппы MadelineProto собирает participants фильтрами
     * channelParticipantsSearch + Kicked + Banned сразу, поэтому выбывшие
     * приходят вместе с живыми и раньше числились в составе вечно.
     */
    public function test_kicked_and_left_participants_are_not_members(): void
    {
        $client = new class
        {
            public function getPwrChat(int|string $peer, bool $fullFetch = true, bool $send = true): array
            {
                return [
                    'participants' => [
                        ['_' => 'channelParticipant', 'role' => 'user', 'user' => ['id' => 1, 'username' => 'ivan', 'first_name' => 'Иван']],
                        ['_' => 'channelParticipantAdmin', 'role' => 'admin', 'user' => ['id' => 2, 'first_name' => 'Марцис']],
                        // Выкинут из чата: тот же конструктор, что и «ограничен», отличает только флаг left.
                        ['_' => 'channelParticipantBanned', 'left' => true, 'role' => 'banned', 'user' => ['id' => 3, 'first_name' => 'Ушедший']],
                        // Отдельный конструктор, роли не получает вовсе.
                        ['_' => 'channelParticipantLeft', 'user' => ['id' => 4, 'first_name' => 'Тоже ушедший']],
                    ],
                ];
            }
        };
        $this->fakeFactory($client);

        $roster = app(TelegramHarvestSyncService::class)->fetchRoster('-1009988');

        $this->assertCount(2, $roster);
        $this->assertSame([1, 2], array_column($roster, 'id'));
    }

    /**
     * Ограниченный в правах участник ЕЩЁ В ЧАТЕ — его нельзя выкидывать из
     * состава заодно с изгнанными: у обоих role='banned', разница только в
     * флаге left (TL_telegram_v225.tl:764).
     */
    public function test_restricted_but_present_member_is_kept_and_relabelled(): void
    {
        $client = new class
        {
            public function getPwrChat(int|string $peer, bool $fullFetch = true, bool $send = true): array
            {
                return [
                    'participants' => [
                        ['_' => 'channelParticipantBanned', 'role' => 'banned', 'user' => ['id' => 5, 'first_name' => 'Ограниченный']],
                    ],
                ];
            }
        };
        $this->fakeFactory($client);

        $roster = app(TelegramHarvestSyncService::class)->fetchRoster('-1009988');

        $this->assertCount(1, $roster);
        $this->assertSame(5, $roster[0]['id']);
        $this->assertSame('restricted', $roster[0]['role']);
    }

    /** Боты остаются в составе — они есть и в самом Telegram (решение оператора). */
    public function test_bots_stay_in_the_roster(): void
    {
        $client = new class
        {
            public function getPwrChat(int|string $peer, bool $fullFetch = true, bool $send = true): array
            {
                return [
                    'participants' => [
                        ['_' => 'channelParticipant', 'role' => 'user', 'user' => ['id' => 6, 'first_name' => 'Zapisi', 'bot' => true]],
                    ],
                ];
            }
        };
        $this->fakeFactory($client);

        $roster = app(TelegramHarvestSyncService::class)->fetchRoster('-1009988');

        $this->assertCount(1, $roster);
        $this->assertTrue($roster[0]['is_bot']);
    }

    /** Прерванный проход не должен обнулять уже снятое: пишем по мере получения. */
    public function test_group_rosters_are_handed_over_per_peer(): void
    {
        $client = new class
        {
            public function getPwrChat(int|string $peer, bool $fullFetch = true, bool $send = true): array
            {
                return ['participants' => [
                    ['_' => 'channelParticipant', 'role' => 'user', 'user' => ['id' => 9, 'first_name' => 'Аня']],
                ]];
            }

            public function getDialogIds(): array
            {
                return [];
            }
        };
        $this->fakeFactory($client);

        $seen = [];
        app(TelegramHarvestSyncService::class)->fetchGroupRosters(
            ['-100111', '-100222'],
            function (string $peer, array $roster) use (&$seen): void {
                $seen[] = $peer;
            },
        );

        $this->assertSame(['-100111', '-100222'], $seen);
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
