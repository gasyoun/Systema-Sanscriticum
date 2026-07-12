<?php

declare(strict_types=1);

namespace App\Services\TelegramZapisi;

use App\Models\ZapisiRosterMember;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\TelegramHarvest\HarvestStoreWriter;
use Throwable;

/**
 * H164 (D9): full member roster of the @zapisi_ORSbot booking chat, read via
 * the ONE shared personal-account MadelineProto session (D1/D7) — the Bot
 * API cannot enumerate a full roster, only the client (user) session can.
 *
 * Writes the roster BOTH to the out-of-git corpus store (authoritative, same
 * convention as Track B's HarvestStoreWriter) AND upserts a thin local cache
 * table (zapisi_roster_members) so the Filament dashboard can query it
 * without touching the raw store on every page load.
 */
class ZapisiRosterSyncService
{
    private ?HarvestStoreWriter $writer = null;

    public function __construct(
        private readonly MadelineClientFactory $clientFactory,
    ) {}

    public function setStoreWriter(HarvestStoreWriter $writer): void
    {
        $this->writer = $writer;
    }

    /**
     * Live roster read. Requires MadelineProto — on hosts without it (or with
     * the support session disabled) this returns a 'disabled'/'missing_madelineproto'
     * status, exactly like TelegramHarvestSyncService::sync().
     *
     * @return array{status: string, chat_id: string, count: int}
     */
    public function sync(string $chatId, string $peer): array
    {
        if (! config('services.telegram_support.enabled')) {
            return ['status' => 'support_session_disabled', 'chat_id' => $chatId, 'count' => 0];
        }

        if (! $this->clientFactory->isConfigured()) {
            return ['status' => 'missing_madelineproto', 'chat_id' => $chatId, 'count' => 0];
        }

        try {
            $client = $this->clientFactory->open();
            $participants = $this->fetchParticipants($client, $peer);
        } catch (Throwable $e) {
            return ['status' => 'error', 'chat_id' => $chatId, 'count' => 0, 'error' => $e->getMessage()];
        }

        $count = $this->ingestNormalized($chatId, $participants);

        return ['status' => 'ok', 'chat_id' => $chatId, 'count' => $count];
    }

    /**
     * Apply store-write + DB upsert to already-normalized roster rows. Shared
     * by the live path and tests (so the pipeline is exercisable without
     * MadelineProto).
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    public function ingestNormalized(string $chatId, array $members): int
    {
        $writer = $this->storeWriter();
        $count = 0;

        foreach ($members as $member) {
            $userId = (string) ($member['telegram_user_id'] ?? '');
            if ($userId === '') {
                continue;
            }

            $record = [
                'peer' => $chatId,
                'telegram_chat_id' => $chatId,
                'access_level' => 'roster',
                'record_type' => 'roster_member',
                'telegram_user_id' => $userId,
                'username' => $member['username'] ?? null,
                'first_name' => $member['first_name'] ?? null,
                'last_name' => $member['last_name'] ?? null,
                'is_bot' => (bool) ($member['is_bot'] ?? false),
                'sent_at' => now()->toIso8601String(),
            ];
            $writer->write($record);

            ZapisiRosterMember::query()->updateOrCreate(
                ['chat_id' => $chatId, 'telegram_user_id' => $userId],
                [
                    'username' => $member['username'] ?? null,
                    'first_name' => $member['first_name'] ?? null,
                    'last_name' => $member['last_name'] ?? null,
                    'is_bot' => (bool) ($member['is_bot'] ?? false),
                    'synced_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchParticipants(object $client, string $peer): array
    {
        // channels.getParticipants is the real MadelineProto top-level call
        // for supergroups/channels (mirrors the getInfo()/getHistory() calls
        // in TelegramHarvestSyncService). Small/basic groups instead expose
        // participants via messages.getFullChat — try both, same fallback
        // pattern as peerInfo() in the sibling harvester service.
        $raw = [];
        if (method_exists($client, 'channels') && method_exists($client->channels, 'getParticipants')) {
            $result = $client->channels->getParticipants([
                'channel' => $peer,
                'filter' => ['_' => 'channelParticipantsRecent'],
                'offset' => 0,
                'limit' => 200,
                'hash' => 0,
            ]);
            $raw = (array) ($result['users'] ?? []);
        } elseif (method_exists($client, 'messages') && method_exists($client->messages, 'getFullChat')) {
            $result = $client->messages->getFullChat(['chat_id' => $peer]);
            $raw = (array) ($result['users'] ?? []);
        }

        return array_values(array_filter(array_map(function ($u): ?array {
            if (! isset($u['id'])) {
                return null;
            }

            return [
                'telegram_user_id' => (string) $u['id'],
                'username' => $u['username'] ?? null,
                'first_name' => $u['first_name'] ?? null,
                'last_name' => $u['last_name'] ?? null,
                'is_bot' => (bool) ($u['bot'] ?? false),
            ];
        }, $raw)));
    }

    private function storeWriter(): HarvestStoreWriter
    {
        if ($this->writer !== null) {
            return $this->writer;
        }

        $path = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));

        return $this->writer = new HarvestStoreWriter($path);
    }
}
