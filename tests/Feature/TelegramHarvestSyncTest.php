<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
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

        $factory = new class($client) extends \App\Services\Telegram\MadelineClientFactory
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
        $this->app->instance(\App\Services\Telegram\MadelineClientFactory::class, $factory);

        $peers = app(TelegramHarvestSyncService::class)->discoverPeers();

        $this->assertCount(2, $peers);
        $this->assertSame(100, $peers[0]['id']);
        $this->assertSame('public', $peers[0]['access_level']);   // есть username
        $this->assertTrue($peers[0]['restricted']);               // D6 noforwards
        $this->assertSame('private_group', $peers[1]['access_level']); // без username
    }
}
