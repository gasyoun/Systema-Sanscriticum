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
}
