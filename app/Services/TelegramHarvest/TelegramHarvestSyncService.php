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
     * D5: read-only peer discovery. Enumerate the account's dialogs and resolve
     * each to id/username/type/access_level/restricted so the operator can pick
     * which to add to TELEGRAM_HARVEST_PEERS. No harvest, no store, no cursor.
     *
     * @return array<int, array{id: ?int, username: ?string, type: string, access_level: string, restricted: bool, title: ?string}>
     */
    public function discoverPeers(): array
    {
        if (! $this->clientFactory->isConfigured()) {
            return [];
        }

        $client = $this->clientFactory->open();
        // getDialogIds() — реальный метод верхнего уровня MadelineProto (как в
        // TelegramSupportSyncService), возвращает массив peer-id. Верхнеуровневого
        // getDialogs() у API нет (он в неймспейсе ->messages->), поэтому и вызов,
        // и method_exists() по нему проваливались: magic __call не виден
        // method_exists() → discoverPeers молча отдавал [] («not configured»).
        if (! method_exists($client, 'getDialogIds')) {
            return [];
        }

        $peers = [];
        $seen = [];
        foreach ((array) $client->getDialogIds() as $dialog) {
            $peer = $this->telegramId($dialog) ?? (is_scalar($dialog) ? $dialog : null);
            if ($peer === null || isset($seen[(string) $peer])) {
                continue;
            }
            $seen[(string) $peer] = true;

            $info = $this->peerInfo($client, $peer);
            $peers[] = [
                'id' => $info['id'],
                'username' => $info['username'],
                'type' => $info['type'],
                'access_level' => $info['access_level'],
                'restricted' => $info['restricted'],
                'title' => $info['title'],
            ];
        }

        return $peers;
    }

    /**
     * D9 (Track C, Uprava/docs/DECISIONS_telegram_harvester.md): full member
     * roster for one peer via the personal-account session — the bot side
     * (getChatAdministrators) only sees admins + observed interactors, which
     * is insufficient for "who's in the chat".
     *
     * @return array<int, array{id: ?int, username: ?string, name: ?string, is_bot: bool}>
     */
    public function fetchRoster(string $peer): array
    {
        if (! $this->clientFactory->isConfigured()) {
            return [];
        }

        $client = $this->clientFactory->open();
        $this->primePeerDatabase($client);

        return $this->pwrRoster($client, $peer);
    }

    /**
     * Снять ростеры сразу для набора peer'ов за ОДНО открытие сессии (один
     * прайминг peer-базы + цикл). Используется командой telegram-harvest:roster-groups.
     *
     * @param  array<int, string>  $peers
     * @return array<string, array<int, array<string, mixed>>> peer => roster (только непустые)
     */
    public function fetchGroupRosters(array $peers): array
    {
        if (! $this->clientFactory->isConfigured()) {
            return [];
        }

        $client = $this->clientFactory->open();
        $this->primePeerDatabase($client);

        $out = [];
        foreach ($peers as $peer) {
            $peer = (string) $peer;
            if ($peer === '') {
                continue;
            }

            $roster = $this->pwrRoster($client, $peer);
            if ($roster !== []) {
                $out[$peer] = $roster;
            }
        }

        return $out;
    }

    /**
     * Прайминг внутренней peer-базы сессии: getDialogIds() кэширует все диалоги
     * аккаунта (включая группы, где он состоит). Без этого getPwrChat по «сырому»
     * -100… id падает с "This peer is not present in the internal peer database".
     * Ошибку прайминга глотаем — pwrRoster всё равно попробует и залогирует свою.
     */
    private function primePeerDatabase(object $client): void
    {
        if (! method_exists($client, 'getDialogIds')) {
            return;
        }

        try {
            $client->getDialogIds();
        } catch (Throwable $e) {
            Log::warning('Telegram harvest roster: peer-DB prime failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Ростер одного peer'а: getPwrChat + нормализация участников, в try/catch —
     * один недоступный/чужой чат (peer-not-present, приватность) не должен ронять
     * весь батч. Пустой массив = «не сняли» (аккаунт не в чате / пустой чат).
     *
     * @return array<int, array<string, mixed>>
     */
    private function pwrRoster(object $client, string $peer): array
    {
        if (! method_exists($client, 'getPwrChat')) {
            return [];
        }

        try {
            // fullfetch=true ОБЯЗАТЕЛЕН: только он разворачивает participants в
            // ['user' => {...}] (PeerHandler::getPwrChat, гейт по $fullfetch), иначе
            // у участников нет id и ростер выходит пустым. Раньше стоял false → «0 снято».
            $chat = $client->getPwrChat($peer, true);
        } catch (Throwable $e) {
            Log::warning('Telegram harvest roster: getPwrChat failed', ['peer' => $peer, 'error' => $e->getMessage()]);

            return [];
        }

        $participants = (array) ($chat['participants'] ?? []);

        $roster = [];
        foreach ($participants as $participant) {
            $user = $participant['user'] ?? $participant;
            if (! is_array($user) || ! isset($user['id'])) {
                continue;
            }

            $roster[] = [
                'id' => (int) $user['id'],
                'username' => $user['username'] ?? null,
                'name' => $this->displayName($user),
                'is_bot' => (bool) ($user['bot'] ?? false),
            ];
        }

        $this->downloadChatAvatar($client, $peer, $chat);

        return $roster;
    }

    /**
     * Best-effort: фото чата (getPwrChat()['photo']) → roster/avatars/{peer}.jpg
     * через ту же сессию, что и ростер (как downloadMedia). Сбой аватара НЕ роняет
     * ростер. Свежий (моложе 7 дней) не перекачиваем — экономим RPC при hourly.
     *
     * @param  array<string, mixed>  $chat
     */
    private function downloadChatAvatar(object $client, string $peer, array $chat): void
    {
        if (empty($chat['photo']) || ! method_exists($client, 'downloadToFile')) {
            return;
        }

        $storePath = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
        $dir = $storePath.'/roster/avatars';
        $file = $dir.'/'.$peer.'.jpg';

        if (File::exists($file) && File::lastModified($file) >= now()->subDays(7)->getTimestamp()) {
            return;
        }

        try {
            File::ensureDirectoryExists($dir);
            $result = $client->downloadToFile($chat['photo'], $file);
            // ДИАГНОСТИКА (временно): что реально вернул downloadToFile и записался ли файл.
            Log::warning('Telegram harvest roster: avatar diag', [
                'peer' => $peer,
                'photo_type' => $chat['photo']['_'] ?? null,
                'result' => is_string($result) ? $result : gettype($result),
                'exists' => File::exists($file),
                'size' => File::exists($file) ? File::size($file) : 0,
            ]);
        } catch (Throwable $e) {
            Log::warning('Telegram harvest roster: avatar download failed', ['peer' => $peer, 'photo_type' => $chat['photo']['_'] ?? null, 'error' => $e->getMessage()]);
        }
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
        // Only messages we actually persisted (stored) or safely already have
        // (skipped dupe) advance the cursor. A failed store must NOT move it, or
        // the next run would skip past a message we never saved — exactly how a
        // broken store path once burned 199 messages on prod.
        $advanceable = [];

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
                $advanceable[] = $message;

                continue;
            }

            try {
                $message['harvested_at'] ??= now()->toIso8601String();
                $message['source_account'] = $account->name;
                $writer->write($message);
                $stored++;
                $advanceable[] = $message;
            } catch (Throwable $e) {
                // Dead-letter lane (clean-room of tracker.py failed_messages): a
                // single bad record must not abort the whole harvest. Deliberately
                // left out of $advanceable so a retry re-fetches it.
                $this->recordFailure($message, $e);
                $failed++;
            }
        }

        $this->advanceCursors($account, $advanceable);

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

        foreach ($peers as $index => $peer) {
            // Anti-ban: randomized inter-peer delay (default 0 → tests never sleep).
            if ($index > 0) {
                $this->interPeerDelay();
            }

            $info = $this->peerInfo($client, $peer);
            $peerId = $info['id'];
            if ($peerId === null) {
                continue;
            }

            $minId = (int) (($peerState[(string) $peerId]['last_message_id']) ?? 0);

            $history = $this->fetchPeerHistory($client, $peer, $limit, $minId);
            $usersById = $this->usersById($history['users']);

            $downloadMedia = $this->shouldDownloadMedia($peer, $info);

            foreach ($history['messages'] as $raw) {
                $normalized = $this->normalize($raw, $info, $usersById);
                if ($normalized !== null && (int) $normalized['telegram_message_id'] > $minId) {
                    if ($downloadMedia && $normalized['has_media']) {
                        $normalized['media_local_path'] = $this->downloadMedia($client, $raw, $normalized);
                    }
                    $messages[] = $normalized;
                }
            }
        }

        return $messages;
    }

    /**
     * Randomized pause between peers to look less bot-like. Bounds come from
     * config (default 0/0 so the test/CI path never sleeps).
     */
    private function interPeerDelay(): void
    {
        $min = (int) config('services.telegram_harvest.peer_delay_min', 0);
        $max = (int) config('services.telegram_harvest.peer_delay_max', 0);
        if ($max <= 0 || $max < $min) {
            return;
        }
        sleep(random_int(max(0, $min), $max));
    }

    /**
     * Page messages.getHistory newest→oldest until the per-peer cursor (min_id),
     * history exhaustion, or the run cap ($limit) is reached. Telegram clamps a
     * single getHistory call to 100 messages, so one call per peer (the old
     * behaviour) silently capped a wound-back cursor at ~100 — a backfill after
     * telegram-harvest:rewind needs real pagination.
     *
     * @return array{messages: array<int, array<string, mixed>>, users: array<int, array<string, mixed>>}
     */
    private function fetchPeerHistory(object $client, string $peer, int $limit, int $minId): array
    {
        $messages = [];
        $users = [];
        $offsetId = 0;

        while (count($messages) < $limit) {
            $batchLimit = min(100, $limit - count($messages));
            if ($messages !== []) {
                // Anti-ban: same randomized pause between pages as between peers.
                $this->interPeerDelay();
            }

            $batch = $this->getHistoryWithBackoff($client, $peer, $batchLimit, $minId, $offsetId);
            $batchMessages = array_values($batch['messages'] ?? []);
            if ($batchMessages === []) {
                break;
            }

            $users = array_merge($users, (array) ($batch['users'] ?? []));
            $messages = array_merge($messages, $batchMessages);

            $last = end($batchMessages);
            $oldestId = isset($last['id']) ? (int) $last['id'] : null;
            // Stop on a short page (range exhausted) or when the server stops
            // making progress (offset not decreasing) — never loop on a stall.
            if (count($batchMessages) < $batchLimit
                || $oldestId === null
                || ($offsetId !== 0 && $oldestId >= $offsetId)) {
                break;
            }
            $offsetId = $oldestId;
        }

        return ['messages' => $messages, 'users' => $users];
    }

    /**
     * Fetch history with a single FloodWait backoff: on FLOOD_WAIT_<n> sleep the
     * advised seconds and retry once (clean-room of the cloner's anti-ban logic).
     *
     * @return array<string, mixed>
     */
    private function getHistoryWithBackoff(object $client, string $peer, int $limit, int $minId, int $offsetId = 0): array
    {
        $params = [
            'peer' => $peer,
            'offset_id' => $offsetId,
            'offset_date' => 0,
            'add_offset' => 0,
            'limit' => $limit,
            'max_id' => 0,
            'min_id' => $minId,
            'hash' => 0,
        ];

        try {
            return $client->messages->getHistory($params);
        } catch (Throwable $e) {
            $wait = $this->floodWaitSeconds($e->getMessage());
            if ($wait === null) {
                throw $e;
            }
            Log::warning('Telegram harvest FLOOD_WAIT backoff', ['peer' => $peer, 'seconds' => $wait]);
            sleep($wait);

            return $client->messages->getHistory($params);
        }
    }

    /**
     * Parse the advised wait from a FLOOD_WAIT_<n> RPC error message, else null.
     */
    private function floodWaitSeconds(string $message): ?int
    {
        if (preg_match('/FLOOD_WAIT_(\d+)/', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Resolve a peer's id, type and public/private access level from MadelineProto.
     *
     * @return array{id: ?int, type: string, title: ?string, username: ?string, access_level: string, restricted: bool}
     */
    private function peerInfo(object $client, int|string $peer): array
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
     * Metadata only by default — the file itself is downloaded only for peers
     * in services.telegram_harvest.media_download_peers (D11 override, Track C).
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
     * D11 override (Track C): only peers explicitly listed in
     * services.telegram_harvest.media_download_peers get their historical
     * media downloaded; every other Track B peer stays D4 metadata-only.
     * Matched against however the peer was given (raw string, @username, or
     * resolved numeric id) so either form in the config list works.
     *
     * @param  array{id: ?int, username: ?string}  $info
     */
    private function shouldDownloadMedia(string $peer, array $info): bool
    {
        $configured = (array) config('services.telegram_harvest.media_download_peers', []);
        if ($configured === []) {
            return false;
        }

        $candidates = array_filter([
            $peer,
            $info['username'] !== null ? '@'.$info['username'] : null,
            $info['id'] !== null ? (string) $info['id'] : null,
        ]);

        return array_intersect($candidates, $configured) !== [];
    }

    /**
     * D11 override (Track C): download the actual media file for a
     * peer-backfill message. Best-effort — a failure here must not abort the
     * harvest (the message itself still stores, metadata-only, via the
     * normal path).
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $normalized
     */
    private function downloadMedia(object $client, array $raw, array $normalized): ?string
    {
        $media = $raw['media'] ?? null;
        if (! is_array($media) || $media === [] || ! method_exists($client, 'downloadToDir')) {
            return null;
        }

        $dir = ((string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw')))
            .'/zapisi_media/'.$normalized['telegram_chat_id'].'/'.substr((string) $normalized['sent_at'], 0, 10);

        try {
            File::ensureDirectoryExists($dir);

            return (string) $client->downloadToDir($media, $dir);
        } catch (Throwable $e) {
            Log::warning('Telegram harvest D11 media download failed', [
                'chat_id' => $normalized['telegram_chat_id'],
                'message_id' => $normalized['telegram_message_id'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
