<?php

namespace App\Services\TelegramHarvest;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Services\Telegram\MadelineClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Track B harvester (Uprava/docs/DECISIONS_telegram_harvester.md, D1-D3).
 *
 * Reads the personal account's Sanskrit groups/channels/DMs over the ONE shared
 * MadelineProto session and lands normalized records in the OUT-OF-GIT raw store
 * (HarvestStoreWriter). It reuses the support subsystem's per-peer cursor
 * machinery via its OWN telegram_support_accounts row (name = 'harvester'), with
 * a cursor namespace fully separate from 'support'. It NEVER writes group/channel
 * corpus content into telegram_support_messages (that would pull PII-heavy group
 * text back into Systema, against D1).
 *
 * Dedup (D3): the shared message identity is (telegram_chat_id, telegram_message_id).
 * Before storing a DM to the corpus, we check whether support-sync already ingested
 * it; DMs are archive-only and never re-stored / never published.
 */
class TelegramHarvestSyncService
{
    private ?HarvestStoreWriter $writer = null;

    public function __construct(
        private readonly MadelineClientFactory $clientFactory,
    ) {}

    /**
     * Override the raw-store writer (tests inject a throwing writer to exercise
     * the dead-letter lane; the live path uses the config-built default).
     */
    public function setStoreWriter(HarvestStoreWriter $writer): void
    {
        $this->writer = $writer;
    }

    /**
     * Live harvest over the configured peer list. Requires MadelineProto
     * (PHP >= 8.2.14 host); on prod PHP 8.1 the package's bootstrap dies, so the
     * guards below short-circuit exactly like telegram-support:sync.
     *
     * @param  array<int, string>  $peerOverride  optional explicit peer list
     * @return array<string, mixed>
     */
    public function sync(array $peerOverride = []): array
    {
        if (! config('services.telegram_harvest.enabled')) {
            return ['status' => 'disabled', 'harvested' => 0, 'stored' => 0, 'skipped_dupe' => 0];
        }

        if (! config('services.telegram_support.enabled')) {
            // The harvester has no session of its own (D1): Track A must be live.
            return ['status' => 'support_session_disabled', 'harvested' => 0, 'stored' => 0, 'skipped_dupe' => 0];
        }

        if (! $this->clientFactory->isConfigured()) {
            return ['status' => 'missing_madelineproto', 'harvested' => 0, 'stored' => 0, 'skipped_dupe' => 0];
        }

        $peers = $peerOverride !== [] ? $peerOverride : $this->configuredPeers();
        if ($peers === []) {
            return ['status' => 'no_peers', 'harvested' => 0, 'stored' => 0, 'skipped_dupe' => 0];
        }

        $account = $this->harvesterAccount();

        try {
            $messages = $this->fetchIncremental($account, $peers);
        } catch (Throwable $e) {
            $account->forceFill(['last_synced_at' => now(), 'last_sync_error' => $e->getMessage()])->save();
            Log::error('Telegram harvest failed', ['error' => $e->getMessage(), 'exception' => $e::class]);

            return ['status' => 'error', 'harvested' => 0, 'stored' => 0, 'skipped_dupe' => 0, 'error' => $e->getMessage()];
        }

        $result = $this->ingestNormalized($messages, $account);
        $result['status'] = 'ok';

        return $result;
    }

    /**
     * Apply dedup + raw-store write + cursor advance to already-normalized
     * records. Shared by the live path, the --payload local-import path, and
     * tests (so the pipeline is exercisable without MadelineProto).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    public function ingestNormalized(array $messages, ?TelegramSupportAccount $account = null): array
    {
        $account ??= $this->harvesterAccount();
        $writer = $this->storeWriter();

        $stored = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($messages as $message) {
            $chatId = (int) ($message['telegram_chat_id'] ?? 0);
            $messageId = (int) ($message['telegram_message_id'] ?? 0);
            if ($chatId === 0 || $messageId === 0) {
                continue;
            }

            // D3: a DM already ingested by support-sync is archive-only and must
            // not be re-stored in the corpus.
            if (($message['access_level'] ?? null) === 'dm'
                && $this->supportAlreadyIngested($chatId, $messageId)) {
                $skipped++;

                continue;
            }

            try {
                $message['harvested_at'] ??= now()->toIso8601String();
                $message['source_account'] = $account->name;
                $writer->write($message);
                $stored++;
            } catch (Throwable $e) {
                // Dead-letter lane (clean-room of tracker.py failed_messages): a
                // single bad record must not abort the whole harvest.
                $this->recordFailure($message, $e);
                $failed++;
            }
        }

        $this->advanceCursors($account, $messages);

        Log::info('Telegram harvest finished', [
            'harvested' => count($messages),
            'stored' => $stored,
            'skipped_dupe' => $skipped,
            'failed' => $failed,
        ]);

        return ['harvested' => count($messages), 'stored' => $stored, 'skipped_dupe' => $skipped, 'failed' => $failed];
    }

    /**
     * Append a failed record's provenance to the out-of-git `_failures/` lane so
     * it can be reprocessed later. Never re-throws (best-effort dead-letter).
     *
     * @param  array<string, mixed>  $message
     */
    private function recordFailure(array $message, Throwable $e): void
    {
        try {
            $path = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
            $dir = $path.'/_failures';
            File::ensureDirectoryExists($dir);
            $entry = [
                'peer' => $message['peer'] ?? null,
                'telegram_message_id' => (int) ($message['telegram_message_id'] ?? 0),
                'error' => $e->getMessage(),
                'at' => now()->toIso8601String(),
            ];
            File::append(
                $dir.'/'.now()->format('Y-m-d').'.jsonl',
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        } catch (Throwable $inner) {
            Log::error('Telegram harvest dead-letter write failed', ['error' => $inner->getMessage()]);
        }
    }

    private function supportAlreadyIngested(int $chatId, int $messageId): bool
    {
        return TelegramSupportMessage::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $messageId)
            ->exists();
    }

    /**
     * @param  array<int, string>  $peers
     * @return array<int, array<string, mixed>>
     */
    private function fetchIncremental(TelegramSupportAccount $account, array $peers): array
    {
        $client = $this->clientFactory->open();
        $limit = (int) config('services.telegram_harvest.history_limit', 200);
        $peerState = $account->sync_state['peers'] ?? [];
        $messages = [];

        foreach ($peers as $peer) {
            $info = $this->peerInfo($client, $peer);
            $peerId = $info['id'];
            if ($peerId === null) {
                continue;
            }

            $minId = (int) (($peerState[(string) $peerId]['last_message_id']) ?? 0);

            $history = $client->messages->getHistory([
                'peer' => $peer,
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => $limit,
                'max_id' => 0,
                'min_id' => $minId,
                'hash' => 0,
            ]);
            $usersById = $this->usersById($history['users'] ?? []);

            foreach (($history['messages'] ?? []) as $raw) {
                $normalized = $this->normalize($raw, $info, $usersById);
                if ($normalized !== null && (int) $normalized['telegram_message_id'] > $minId) {
                    $messages[] = $normalized;
                }
            }
        }

        return $messages;
    }

    /**
     * Resolve a peer's id, type and public/private access level from MadelineProto.
     *
     * @return array{id: ?int, type: string, title: ?string, username: ?string, access_level: string, restricted: bool}
     */
    private function peerInfo(object $client, string $peer): array
    {
        $default = ['id' => null, 'type' => 'unknown', 'title' => null, 'username' => null, 'access_level' => 'private_group', 'restricted' => false];

        if (! method_exists($client, 'getInfo')) {
            return $default;
        }

        try {
            $info = $client->getInfo($peer);
        } catch (Throwable $e) {
            Log::warning('Telegram harvest peer resolve failed', ['peer' => $peer, 'error' => $e->getMessage()]);

            return $default;
        }

        $chat = $info['Chat'] ?? $info['chat'] ?? $info['User'] ?? $info['user'] ?? $info;
        $type = (string) ($chat['_'] ?? 'unknown');
        $username = $chat['username'] ?? null;

        $accessLevel = match (true) {
            $type === 'user' => 'dm',
            // Broadcast/public supergroups expose a username; treat those as public.
            $username !== null && $username !== '' => 'public',
            default => 'private_group',
        };

        return [
            'id' => isset($chat['id']) ? (int) $chat['id'] : null,
            'type' => $type,
            'title' => $chat['title'] ?? null,
            'username' => $username,
            'access_level' => $accessLevel,
            // D6: channels/groups that disable saving/forwarding set noforwards.
            'restricted' => (bool) ($chat['noforwards'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array{id: ?int, type: string, title: ?string, username: ?string, access_level: string}  $info
     * @param  array<int, array<string, mixed>>  $usersById
     * @return array<string, mixed>|null
     */
    private function normalize(array $raw, array $info, array $usersById): ?array
    {
        if (! isset($raw['id'], $raw['date'])) {
            return null;
        }

        $text = (string) ($raw['message'] ?? '');
        $media = $this->mediaMeta($raw);

        // D4: keep media-bearing messages (previously dropped) — text OR media qualifies.
        if ($text === '' && ! $media['has_media']) {
            return null;
        }

        $authorId = $this->telegramId($raw['from_id'] ?? null);
        $author = $authorId ? ($usersById[$authorId] ?? null) : null;

        return [
            'peer' => $info['username'] ? '@'.$info['username'] : (string) $info['id'],
            'telegram_chat_id' => $info['id'],
            'telegram_message_id' => (int) $raw['id'],
            'peer_type' => $info['type'],
            'peer_title' => $info['title'],
            'peer_username' => $info['username'],
            'access_level' => $info['access_level'],
            // D6: record the noforwards/restricted flag for the publication gate; harvest anyway.
            'peer_restricted' => (bool) ($info['restricted'] ?? false),
            'telegram_user_id' => $authorId,
            'author_name' => $author ? $this->displayName($author) : null,
            'author_username' => $author['username'] ?? null,
            'direction' => ! empty($raw['out']) ? 'outgoing' : 'incoming',
            'text' => $text,
            // D4: media metadata only — never download the file.
            'has_media' => $media['has_media'],
            'media_type' => $media['media_type'],
            'media_caption' => $media['has_media'] && $text !== '' ? $text : null,
            'media_size' => $media['media_size'],
            'media_mime' => $media['media_mime'],
            'sent_at' => CarbonImmutable::createFromTimestamp((int) $raw['date'], config('app.timezone'))->toIso8601String(),
        ];
    }

    /**
     * D4: extract media presence + metadata from a raw MadelineProto message.
     * Metadata only — the file itself is NEVER downloaded (a future D## if taken).
     *
     * @param  array<string, mixed>  $raw
     * @return array{has_media: bool, media_type: ?string, media_size: ?int, media_mime: ?string}
     */
    private function mediaMeta(array $raw): array
    {
        $media = $raw['media'] ?? null;
        if (! is_array($media) || $media === []) {
            return ['has_media' => false, 'media_type' => null, 'media_size' => null, 'media_mime' => null];
        }

        $kind = (string) ($media['_'] ?? '');
        $document = is_array($media['document'] ?? null) ? $media['document'] : [];
        $mime = isset($document['mime_type']) ? (string) $document['mime_type'] : null;
        $size = isset($document['size']) ? (int) $document['size'] : null;

        // photo/document/audio/video/… — prefer the document mime prefix, fall
        // back to the MadelineProto media constructor name.
        $type = match (true) {
            $kind === 'messageMediaPhoto' => 'photo',
            $mime !== null && str_starts_with($mime, 'audio/') => 'audio',
            $mime !== null && str_starts_with($mime, 'video/') => 'video',
            $mime !== null && str_starts_with($mime, 'image/') => 'image',
            $kind === 'messageMediaDocument' => 'document',
            $kind !== '' => (string) preg_replace('/^messageMedia/', '', $kind),
            default => 'unknown',
        };

        return [
            'has_media' => true,
            'media_type' => strtolower($type),
            'media_size' => $size,
            'media_mime' => $mime,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function advanceCursors(TelegramSupportAccount $account, array $messages): void
    {
        $state = $account->sync_state ?? [];
        $state['peers'] ??= [];

        foreach ($messages as $message) {
            $peerKey = (string) ($message['telegram_chat_id'] ?? '');
            if ($peerKey === '') {
                continue;
            }
            $messageId = (int) ($message['telegram_message_id'] ?? 0);
            if ($messageId >= (int) (($state['peers'][$peerKey]['last_message_id']) ?? 0)) {
                $state['peers'][$peerKey] = [
                    'last_message_id' => $messageId,
                    'last_sent_at' => $message['sent_at'] ?? null,
                ];
            }
        }

        $account->forceFill([
            'sync_state' => $state,
            'last_synced_at' => now(),
            'last_successful_sync_at' => now(),
            'last_sync_error' => null,
        ])->save();
    }

    private function harvesterAccount(): TelegramSupportAccount
    {
        return TelegramSupportAccount::firstOrCreate(
            ['name' => (string) config('services.telegram_harvest.account_name', 'harvester')],
            [
                'session_path' => config('services.telegram_support.session'),
                'api_id' => config('services.telegram_support.api_id'),
                'is_enabled' => (bool) config('services.telegram_harvest.enabled'),
            ],
        );
    }

    private function storeWriter(): HarvestStoreWriter
    {
        if ($this->writer !== null) {
            return $this->writer;
        }

        $path = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));

        return $this->writer = new HarvestStoreWriter($path);
    }

    /**
     * @return array<int, string>
     */
    private function configuredPeers(): array
    {
        $peers = (array) config('services.telegram_harvest.peers', []);

        $file = config('services.telegram_harvest.peers_file');
        if ($file && is_string($file) && is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $peers = array_merge($peers, $decoded);
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($p) => is_string($p) ? trim($p) : (string) $p,
            $peers,
        ))));
    }

    private function telegramId(mixed $value): ?int
    {
        if (is_int($value) || is_string($value)) {
            return (int) $value;
        }
        if (is_array($value)) {
            foreach (['user_id', 'chat_id', 'channel_id'] as $key) {
                if (isset($value[$key])) {
                    return (int) $value[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @return array<int, array<string, mixed>>
     */
    private function usersById(array $users): array
    {
        $indexed = [];
        foreach ($users as $user) {
            if (isset($user['id'])) {
                $indexed[(int) $user['id']] = $user;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function displayName(array $user): ?string
    {
        $name = trim(implode(' ', array_filter([$user['first_name'] ?? null, $user['last_name'] ?? null])));

        return $name !== '' ? $name : ($user['username'] ?? null);
    }
}
