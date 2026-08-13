<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\TelegramHarvest\HarvestStoreWriter;
use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class TelegramHarvestSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = storage_path('framework/testing/harvest-'.uniqid());
        config(['services.telegram_harvest.store_path' => $this->store]);
        config(['services.telegram_harvest.account_name' => 'harvester']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function record(int $chatId, int $messageId, string $access, string $sentAt): array
    {
        return [
            'peer' => '@peer'.$chatId,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => $messageId,
            'peer_type' => $access === 'dm' ? 'user' : 'channel',
            'access_level' => $access,
            'text' => 'oṃ namaḥ śivāya '.$messageId,
            'sent_at' => $sentAt,
        ];
    }

    public function test_group_messages_are_stored_and_dms_already_in_support_are_skipped(): void
    {
        // A DM support-sync already ingested (shared identity key).
        $support = TelegramSupportAccount::create(['name' => 'support', 'is_enabled' => true]);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 555, 'type' => 'private']);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $support->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 555,
            'telegram_message_id' => 900,
            'direction' => 'incoming',
            'sent_at' => now(),
        ]);
        $supportMessageCountBefore = TelegramSupportMessage::count();

        $service = app(TelegramHarvestSyncService::class);
        $result = $service->ingestNormalized([
            $this->record(100, 10, 'public', '2026-07-01T10:00:00+03:00'),        // group → store
            $this->record(555, 900, 'dm', '2026-07-01T11:00:00+03:00'),           // DM dup → skip
            $this->record(777, 5, 'dm', '2026-07-01T12:00:00+03:00'),             // fresh DM → archive lane
        ]);

        $this->assertSame(3, $result['harvested']);
        $this->assertSame(2, $result['stored']);
        $this->assertSame(1, $result['skipped_dupe']);

        // D1/D3: telegram_support_messages is NOT widened with harvested content.
        $this->assertSame($supportMessageCountBefore, TelegramSupportMessage::count());

        // Raw store: group → corpus lane, fresh DM → archive lane.
        $this->assertFileExists($this->store.'/corpus/100/2026-07-01.jsonl');
        $this->assertFileExists($this->store.'/archive/777/2026-07-01.jsonl');
        $this->assertFileDoesNotExist($this->store.'/corpus/555/2026-07-01.jsonl');

        // Harvester keeps its OWN cursors under its own account row.
        $harvester = TelegramSupportAccount::where('name', 'harvester')->firstOrFail();
        $this->assertSame(10, $harvester->sync_state['peers']['100']['last_message_id']);
        $this->assertNotEquals('support', $harvester->name);
    }

    public function test_reingesting_the_same_group_message_advances_cursor_without_error(): void
    {
        $service = app(TelegramHarvestSyncService::class);
        $service->ingestNormalized([$this->record(100, 10, 'public', '2026-07-01T10:00:00+03:00')]);
        $result = $service->ingestNormalized([$this->record(100, 12, 'public', '2026-07-01T10:05:00+03:00')]);

        $harvester = TelegramSupportAccount::where('name', 'harvester')->firstOrFail();
        $this->assertSame(12, $harvester->sync_state['peers']['100']['last_message_id']);
        $this->assertSame(1, $result['stored']);
    }

    /** @param array<string, mixed> $raw @param array<string, mixed> $info @return array<string, mixed>|null */
    private function normalize(array $raw, array $info): ?array
    {
        $m = new ReflectionMethod(TelegramHarvestSyncService::class, 'normalize');
        $m->setAccessible(true);

        return $m->invoke(app(TelegramHarvestSyncService::class), $raw, $info, []);
    }

    public function test_d4_media_message_gets_metadata_and_d6_records_restricted(): void
    {
        $info = ['id' => 100, 'type' => 'channel', 'title' => 'Sanskrit', 'username' => 'sanskrit', 'access_level' => 'public', 'restricted' => true];
        $raw = [
            'id' => 42,
            'date' => 1751360400,
            'message' => 'манускрипт',
            'media' => ['_' => 'messageMediaDocument', 'document' => ['size' => 1234, 'mime_type' => 'image/jpeg']],
        ];

        $rec = $this->normalize($raw, $info);

        $this->assertNotNull($rec);
        $this->assertTrue($rec['has_media']);
        $this->assertSame('image', $rec['media_type']);
        $this->assertSame(1234, $rec['media_size']);
        $this->assertSame('image/jpeg', $rec['media_mime']);
        $this->assertSame('манускрипт', $rec['media_caption']);
        $this->assertTrue($rec['peer_restricted']);   // D6
    }

    public function test_d4_media_only_message_is_kept_previously_dropped(): void
    {
        $info = ['id' => 100, 'type' => 'channel', 'title' => 'S', 'username' => 'sanskrit', 'access_level' => 'public', 'restricted' => false];

        // Media-only (empty text) — the old text-only guard would have dropped this.
        $rec = $this->normalize(['id' => 43, 'date' => 1751360400, 'message' => '', 'media' => ['_' => 'messageMediaPhoto']], $info);
        $this->assertNotNull($rec);
        $this->assertTrue($rec['has_media']);
        $this->assertSame('photo', $rec['media_type']);
        $this->assertNull($rec['media_caption']);
        $this->assertSame('', $rec['text']);

        // A text-only message carries no media metadata but is still kept.
        $text = $this->normalize(['id' => 44, 'date' => 1751360400, 'message' => 'oṃ'], $info);
        $this->assertFalse($text['has_media']);
        $this->assertNull($text['media_type']);
        $this->assertFalse($text['peer_restricted']);

        // Truly empty (no text, no media) is still dropped.
        $this->assertNull($this->normalize(['id' => 45, 'date' => 1751360400, 'message' => ''], $info));
    }

    public function test_store_failure_is_dead_lettered_and_counted(): void
    {
        $throwing = new class('unused') extends HarvestStoreWriter
        {
            public function write(array $record): string
            {
                throw new \RuntimeException('boom');
            }
        };

        $service = app(TelegramHarvestSyncService::class);
        $service->setStoreWriter($throwing);
        $result = $service->ingestNormalized([$this->record(100, 10, 'public', '2026-07-01T10:00:00+03:00')]);

        $this->assertSame(0, $result['stored']);
        $this->assertSame(1, $result['failed']);
        $failFile = $this->store.'/_failures/'.now()->format('Y-m-d').'.jsonl';
        $this->assertFileExists($failFile);
        $this->assertStringContainsString('boom', File::get($failFile));

        // A failed store must NOT advance the cursor — otherwise the next run
        // skips past a message we never saved (the prod "199 burned" incident).
        $harvester = TelegramSupportAccount::where('name', 'harvester')->first();
        $this->assertNull($harvester?->sync_state['peers']['100']['last_message_id'] ?? null);

        // With a healthy writer the retry re-fetches the same id and stores it,
        // and only then does the cursor advance.
        $service->setStoreWriter(new HarvestStoreWriter($this->store));
        $retry = $service->ingestNormalized([$this->record(100, 10, 'public', '2026-07-01T10:00:00+03:00')]);
        $this->assertSame(1, $retry['stored']);
        $this->assertSame(10, TelegramSupportAccount::where('name', 'harvester')
            ->firstOrFail()->sync_state['peers']['100']['last_message_id']);
    }

    public function test_empty_store_path_env_falls_back_to_default(): void
    {
        // Regression: TELEGRAM_HARVEST_STORE_PATH= (present but empty) must not
        // yield '' (which made the writer build /corpus/… from filesystem root
        // → mkdir Permission denied). env(key, default) keeps the '';  `?:` does not.
        putenv('TELEGRAM_HARVEST_STORE_PATH=');
        $_ENV['TELEGRAM_HARVEST_STORE_PATH'] = '';
        $_SERVER['TELEGRAM_HARVEST_STORE_PATH'] = '';

        try {
            $config = require base_path('config/services.php');
            $this->assertSame(
                storage_path('app/telegram-harvest/raw'),
                $config['telegram_harvest']['store_path'],
            );
        } finally {
            putenv('TELEGRAM_HARVEST_STORE_PATH');
            unset($_ENV['TELEGRAM_HARVEST_STORE_PATH'], $_SERVER['TELEGRAM_HARVEST_STORE_PATH']);
        }
    }

    public function test_sync_json_is_machine_readable_and_store_failures_exit_nonzero(): void
    {
        $payload = storage_path('framework/testing/harvest-payload-'.uniqid().'.json');
        File::put($payload, json_encode([$this->record(987654321, 987654321, 'public', '2026-07-01T10:00:00+03:00')]));

        try {
            $this->artisan('telegram-harvest:sync', ['--payload' => $payload, '--json' => true])
                ->expectsOutputToContain('"status":"ok"')
                ->assertExitCode(0);
        } finally {
            File::delete($payload);
        }
    }

    public function test_live_sync_paginates_history_past_the_100_message_api_clamp(): void
    {
        config([
            'services.telegram_harvest.enabled' => true,
            'services.telegram_support.enabled' => true,
            'services.telegram_harvest.history_limit' => 250,
        ]);

        // Telegram клампит messages.getHistory до 100 за вызов — один вызов на
        // пира (старое поведение) молча резал бэкфилл после rewind до ~100.
        $client = new class
        {
            /** @var array<int, array<string, mixed>> */
            public array $historyCalls = [];

            public object $messages;

            public function __construct()
            {
                $this->messages = new class($this)
                {
                    public function __construct(private object $client) {}

                    /** @param array<string, mixed> $params @return array<string, mixed> */
                    public function getHistory(array $params): array
                    {
                        return $this->client->history($params);
                    }
                };
            }

            /** @return array<string, mixed> */
            public function getInfo(int|string $peer): array
            {
                return ['_' => 'channel', 'id' => 100, 'title' => 'Sanskrit', 'username' => 'sanskrit'];
            }

            /**
             * 250 сообщений (id 1..250), newest-first, честные offset_id/min_id/limit.
             *
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            public function history(array $params): array
            {
                $this->historyCalls[] = $params;
                $start = $params['offset_id'] > 0 ? $params['offset_id'] - 1 : 250;
                $out = [];
                for ($id = $start; $id > $params['min_id'] && count($out) < $params['limit']; $id--) {
                    $out[] = ['id' => $id, 'date' => 1751360400, 'message' => 'oṃ '.$id];
                }

                return ['messages' => $out, 'users' => []];
            }
        };

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

        $result = app(TelegramHarvestSyncService::class)->sync(['@sanskrit']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(250, $result['stored']);

        // Пейджинг: 100 + 100 + 50, лимит вызова никогда не выше API-клампа.
        $this->assertCount(3, $client->historyCalls);
        $this->assertSame([0, 151, 51], array_column($client->historyCalls, 'offset_id'));
        $this->assertLessThanOrEqual(100, max(array_column($client->historyCalls, 'limit')));

        $harvester = TelegramSupportAccount::where('name', 'harvester')->firstOrFail();
        $this->assertSame(250, $harvester->sync_state['peers']['100']['last_message_id']);

        // Инкрементальный повтор: курсор на вершине → min_id отдаёт пусто.
        $again = app(TelegramHarvestSyncService::class)->sync(['@sanskrit']);
        $this->assertSame(0, $again['harvested']);
        $this->assertSame(0, $again['stored']);
    }

    public function test_d11_downloads_media_only_for_configured_peers(): void
    {
        config([
            'services.telegram_harvest.enabled' => true,
            'services.telegram_support.enabled' => true,
            'services.telegram_harvest.media_download_peers' => ['@zapisi_ORSbot'],
        ]);

        $client = new class
        {
            /** @var array<int, array<string, mixed>> */
            public array $downloadCalls = [];

            public object $messages;

            public function __construct()
            {
                $this->messages = new class
                {
                    /** @param array<string, mixed> $params @return array<string, mixed> */
                    public function getHistory(array $params): array
                    {
                        return [
                            'messages' => [
                                ['id' => 1, 'date' => 1751360400, 'message' => 'снимок', 'media' => ['_' => 'messageMediaPhoto']],
                            ],
                            'users' => [],
                        ];
                    }
                };
            }

            /** @return array<string, mixed> */
            public function getInfo(int|string $peer): array
            {
                return ['_' => 'chat', 'id' => 999, 'title' => 'Zapisi chat'];
            }

            public function downloadToDir(array $media, string $dir): string
            {
                $this->downloadCalls[] = ['media' => $media, 'dir' => $dir];

                return $dir.'/1.jpg';
            }
        };

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

        $result = app(TelegramHarvestSyncService::class)->sync(['@zapisi_ORSbot']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['stored']);
        $this->assertCount(1, $client->downloadCalls);
        $this->assertSame($this->store.'/zapisi_media/999/2025-07-01', $client->downloadCalls[0]['dir']);
    }

    public function test_rewind_command_lists_and_winds_back_cursors(): void
    {
        TelegramSupportAccount::create([
            'name' => 'harvester',
            'is_enabled' => true,
            'sync_state' => ['peers' => [
                '100' => ['last_message_id' => 250, 'last_sent_at' => '2026-07-01T10:00:00+03:00'],
                '200' => ['last_message_id' => 40, 'last_sent_at' => null],
            ]],
        ]);
        $cursor = fn (string $key): int => (int) TelegramSupportAccount::where('name', 'harvester')
            ->firstOrFail()->sync_state['peers'][$key]['last_message_id'];

        // Без опций — только листинг, ничего не мутирует.
        $this->artisan('telegram-harvest:rewind')->assertExitCode(0);
        $this->assertSame(250, $cursor('100'));

        // Отмотка одного пира к 0; второй не тронут.
        $this->artisan('telegram-harvest:rewind', ['--peer' => ['100']])->assertExitCode(0);
        $this->assertSame(0, $cursor('100'));
        $this->assertSame(40, $cursor('200'));

        // --to выше текущего курсора НЕ двигает его вперёд (иначе пропуск
        // несохранённых сообщений — режим отказа «199 сгоревших»).
        $this->artisan('telegram-harvest:rewind', ['--peer' => ['200'], '--to' => '90'])->assertExitCode(0);
        $this->assertSame(40, $cursor('200'));

        // --all мотает все оставшиеся ненулевые курсоры.
        $this->artisan('telegram-harvest:rewind', ['--all' => true])->assertExitCode(0);
        $this->assertSame(0, $cursor('200'));
    }

    public function test_discover_peers_uses_get_dialog_ids_and_resolves_info(): void
    {
        // Регресс на реальный MadelineProto: верхнеуровневого getDialogs() нет,
        // discoverPeers обязан ходить через getDialogIds() (+ getInfo() на пир).
        $client = new class
        {
            public function getDialogIds(): array
            {
                return [100, -200];
            }

            public function getInfo(int|string $peer): array
            {
                return $peer === 100
                    ? ['_' => 'channel', 'id' => 100, 'title' => 'Sanskrit', 'username' => 'sanskrit', 'noforwards' => true]
                    : ['_' => 'chat', 'id' => -200, 'title' => 'ЛС-группа'];
            }
        };

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

        $peers = app(TelegramHarvestSyncService::class)->discoverPeers();

        $this->assertCount(2, $peers);
        $this->assertSame(100, $peers[0]['id']);
        $this->assertSame('public', $peers[0]['access_level']);   // есть username
        $this->assertTrue($peers[0]['restricted']);               // D6 noforwards
        $this->assertSame('private_group', $peers[1]['access_level']); // без username
    }
}
