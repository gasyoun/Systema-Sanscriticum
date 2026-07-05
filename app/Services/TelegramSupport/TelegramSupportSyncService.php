<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportResponderMapping;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TelegramSupportSyncService
{
    private static bool $loggedMissingTelegramIdColumn = false;

    public function __construct(
        private readonly SupportDailyRollupAggregator $aggregator,
        private readonly SupportContactUserAutoLinker $autoLinker,
        private readonly \App\Services\Support\SupportConversationManager $conversations,
    ) {}

    public function sync(): array
    {
        if (! config('services.telegram_support.enabled')) {
            Log::info('Telegram support sync skipped', ['status' => 'disabled']);

            return ['status' => 'disabled', 'synced' => 0];
        }

        $account = $this->supportAccount();

        if (! config('services.telegram_support.api_id') || ! config('services.telegram_support.api_hash')) {
            return $this->finish($account, ['status' => 'unconfigured', 'synced' => 0]);
        }

        $clientClass = (string) config('services.telegram_support.client_class');
        if ($clientClass === '' || ! class_exists($clientClass)) {
            return $this->finish($account, ['status' => 'missing_madelineproto', 'synced' => 0]);
        }

        try {
            $messages = $this->fetchIncrementalMadelineMessagesWithRetry($account, $clientClass);
            if ($messages === []) {
                $linkResult = $this->autoLinker->linkUnlinkedContacts();

                return $this->finish($account, [
                    'status' => 'ok',
                    'synced' => 0,
                    'dates' => [],
                    'auto_linked' => $linkResult['linked'],
                ], true, []);
            }

            $result = $this->syncNormalizedMessages($messages, $account->name);
            $this->updateSyncState($account->refresh(), $messages);

            return $this->finish($account, $result, true, $messages);
        } catch (Throwable $e) {
            return $this->fail($account, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchIncrementalMadelineMessagesWithRetry(TelegramSupportAccount $account, string $clientClass): array
    {
        try {
            return $this->fetchIncrementalMadelineMessages($account, $clientClass);
        } catch (Throwable $e) {
            if (! $this->isMadelineAuthRestart($e)) {
                throw $e;
            }

            Log::warning('Telegram support sync restarting MadelineProto auth flow after AUTH_RESTART');

            return $this->fetchIncrementalMadelineMessages($account->refresh(), $clientClass);
        }
    }

    private function isMadelineAuthRestart(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'AUTH_RESTART');
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function syncNormalizedMessages(array $messages, string $accountName = 'support'): array
    {
        $account = TelegramSupportAccount::updateOrCreate(
            ['name' => $accountName],
            [
                'session_path' => config('services.telegram_support.session'),
                'api_id' => config('services.telegram_support.api_id'),
                'is_enabled' => (bool) config('services.telegram_support.enabled'),
            ],
        );

        $affectedDates = collect();
        $synced = 0;

        foreach ($messages as $payload) {
            $message = $this->persistNormalizedMessage($account, $payload);
            $affectedDates->push($message->sent_at->timezone(config('app.timezone'))->toDateString());
            $synced++;
        }

        $affectedDates->unique()->each(fn (string $date) => $this->aggregator->aggregateDate($date));
        $linkResult = $this->autoLinker->linkUnlinkedContacts();

        return [
            'status' => 'ok',
            'synced' => $synced,
            'dates' => $affectedDates->unique()->values()->all(),
            'auto_linked' => $linkResult['linked'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistNormalizedMessage(
        TelegramSupportAccount $account,
        array $payload,
    ): TelegramSupportMessage {
        $chatId = (int) $payload['telegram_chat_id'];
        $messageId = (int) $payload['telegram_message_id'];
        $sentAt = CarbonImmutable::parse($payload['sent_at'] ?? now(), config('app.timezone'));
        $direction = (string) ($payload['direction'] ?? 'incoming');
        $telegramUserId = Arr::get($payload, 'telegram_user_id');
        $linkedUser = $this->linkedUserFromTelegramId($telegramUserId);

        $chat = TelegramSupportChat::firstOrNew(['telegram_chat_id' => $chatId]);
        $chat->fill([
            'linked_user_id' => $chat->linked_user_id ?: $linkedUser?->id,
            'type' => (string) ($payload['chat_type'] ?? $chat->type ?? 'private'),
            'title' => $payload['chat_title'] ?? $chat->title,
            'username' => $payload['chat_username'] ?? $chat->username,
            'first_seen_at' => $chat->first_seen_at
                ? min($chat->first_seen_at, $sentAt)
                : $sentAt,
            'last_message_at' => $chat->last_message_at
                ? max($chat->last_message_at, $sentAt)
                : $sentAt,
        ]);
        $chat->save();

        $contact = $this->upsertContact($chat, $payload, $sentAt, $linkedUser, $direction);
        $responder = $this->resolveResponder($payload, $direction);

        $message = TelegramSupportMessage::updateOrCreate(
            [
                'telegram_support_account_id' => $account->id,
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => $messageId,
            ],
            [
                'telegram_support_chat_id' => $chat->id,
                'telegram_support_contact_id' => $contact?->id,
                'direction' => $direction,
                'role' => $responder['role'],
                'responder_type' => $responder['responder_type'],
                'responder_user_id' => $responder['responder_user_id'],
                'responder_marker' => $responder['responder_marker'],
                'ai_state' => $responder['ai_state'],
                'text' => $payload['text'] ?? null,
                'raw_payload' => $payload,
                'sent_at' => $sentAt,
            ],
        );

        if (in_array($message->ai_state, ['suggested', 'sent'], true)) {
            SupportAiReplyEvent::updateOrCreate(
                [
                    'telegram_support_message_id' => $message->id,
                    'event_type' => $message->ai_state,
                ],
                ['meta' => ['source' => 'telegram_support_sync']],
            );
        }

        // Привязываем к операционному треду, если чат/контакт сведён с пользователем.
        $linkedUserId = $chat->linked_user_id ?: $contact?->linked_user_id;
        if ($linkedUserId) {
            $this->conversations->recordMessage($linkedUserId, $message, $message->sent_at);
        }

        return $message;
    }

    private function linkedUserFromTelegramId(mixed $telegramUserId): ?User
    {
        if (! $telegramUserId) {
            return null;
        }

        if (! Schema::hasColumn('users', 'telegram_id')) {
            if (! self::$loggedMissingTelegramIdColumn) {
                Log::warning('Telegram support auto-link disabled: users.telegram_id column is missing');
                self::$loggedMissingTelegramIdColumn = true;
            }

            return null;
        }

        return User::where('telegram_id', (string) $telegramUserId)
            ->orWhere('telegram_id', (int) $telegramUserId)
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchIncrementalMadelineMessages(TelegramSupportAccount $account, string $clientClass): array
    {
        $client = $this->openClient($clientClass);
        $this->backfillContactProfiles($client);

        $limit = (int) config('services.telegram_support.history_limit', 50);
        $dialogs = $this->limitedDialogs($client->getDialogIds());
        $messages = [];
        $peerState = $account->sync_state['peers'] ?? [];

        foreach ($dialogs as $peer) {
            $peerId = $this->extractTelegramId($peer);
            $cursor = $peerId ? ($peerState[(string) $peerId] ?? []) : [];
            $minId = (int) ($cursor['last_message_id'] ?? 0);

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

            foreach (($history['messages'] ?? []) as $message) {
                $normalized = $this->normalizeMadelineMessage($peer, $message, $usersById);
                if ($normalized !== null && (int) $normalized['telegram_message_id'] > $minId) {
                    $messages[] = $normalized;
                }
            }
        }

        return collect($messages)
            ->sortBy([
                ['sent_at', 'asc'],
                ['telegram_message_id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $dialogs
     * @return array<int, mixed>
     */
    private function limitedDialogs(array $dialogs): array
    {
        $limit = (int) config('services.telegram_support.dialog_limit', 20);

        if ($limit <= 0) {
            return $dialogs;
        }

        return array_slice($dialogs, 0, $limit);
    }

    /** Открыть залогиненный MadelineProto-клиент (та же сессия, что и для sync). */
    protected function openClient(string $clientClass): object
    {
        // Абсолютный путь через фабрику: дефолт конфига — storage_path() (уже
        // абсолютный), оборачивать его в base_path() нельзя (задвоение пути).
        $session = \App\Services\Telegram\MadelineClientFactory::sessionPath();
        File::ensureDirectoryExists(dirname($session));

        $client = new $clientClass($session, $this->madelineSettings($clientClass));
        $client->start();

        return $client;
    }

    /**
     * Отправить исходящее сообщение через userbot в указанный чат. Возвращает
     * статус + реальный telegram_message_id при успехе. Гварды повторяют sync():
     * без enabled/creds/клиента — просто статус, без открытия клиента. Ошибки
     * самой отправки пробрасываются (job их ретраит).
     *
     * @return array{status: string, telegram_message_id?: int|null}
     */
    public function deliverMessage(int $chatId, string $text): array
    {
        if (! config('services.telegram_support.enabled')) {
            return ['status' => 'disabled'];
        }

        if (! config('services.telegram_support.api_id') || ! config('services.telegram_support.api_hash')) {
            return ['status' => 'unconfigured'];
        }

        $clientClass = (string) config('services.telegram_support.client_class');
        if ($clientClass === '' || ! class_exists($clientClass)) {
            return ['status' => 'missing_madelineproto'];
        }

        $result = $this->openClient($clientClass)->messages->sendMessage([
            'peer' => $chatId,
            'message' => $text,
        ]);

        return ['status' => 'ok', 'telegram_message_id' => $this->extractSentMessageId($result)];
    }

    /** Достать реальный id отправленного сообщения из ответа MadelineProto (Updates). */
    private function extractSentMessageId(mixed $result): ?int
    {
        if (! is_array($result)) {
            return null;
        }

        foreach (($result['updates'] ?? []) as $update) {
            if (is_array($update) && ($update['_'] ?? null) === 'updateMessageID' && isset($update['id'])) {
                return (int) $update['id'];
            }
        }

        return isset($result['id']) ? (int) $result['id'] : null;
    }

    private function madelineSettings(string $clientClass): object
    {
        // Реальные Settings — только для настоящего API-клиента: загрузка
        // MadelineProto ставит глобальный error-handler, эскалирующий
        // warnings/deprecations в исключения (в тестах с фейком это роняет
        // остальной сьют). Фейки настройки не читают.
        if (! is_a($clientClass, 'danog\\MadelineProto\\API', true)) {
            return new \stdClass;
        }

        $settingsClass = 'danog\\MadelineProto\\Settings';
        $settings = new $settingsClass;
        $settings->getAppInfo()
            ->setApiId((int) config('services.telegram_support.api_id'))
            ->setApiHash((string) config('services.telegram_support.api_hash'));
        $settings->getLogger()
            ->setType($this->madelineLoggerClass()::FILE_LOGGER)
            ->setExtra(storage_path('logs/madelineproto.log'))
            ->setLevel($this->madelineLoggerClass()::LEVEL_WARNING);

        return $settings;
    }

    private function madelineLoggerClass(): string
    {
        return 'danog\\MadelineProto\\Logger';
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<int, array<string, mixed>>  $usersById
     * @return array<string, mixed>|null
     */
    private function normalizeMadelineMessage(mixed $peer, array $message, array $usersById = []): ?array
    {
        if (! isset($message['id']) || ! isset($message['date'])) {
            return null;
        }

        $text = $message['message'] ?? null;
        if ($text === null || $text === '') {
            return null;
        }

        $chatId = $this->extractTelegramId($message['peer_id'] ?? $peer);
        if ($chatId === null) {
            return null;
        }
        $telegramUserId = $this->extractTelegramId($message['from_id'] ?? null);
        if (! $telegramUserId && empty($message['out']) && $this->isPrivatePeer($message['peer_id'] ?? $peer)) {
            $telegramUserId = $chatId;
        }
        $sender = $telegramUserId ? ($usersById[$telegramUserId] ?? null) : null;

        return [
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => (int) $message['id'],
            'telegram_user_id' => $telegramUserId,
            'direction' => ! empty($message['out']) ? 'outgoing' : 'incoming',
            'text' => $text,
            'sent_at' => CarbonImmutable::createFromTimestamp((int) $message['date'], config('app.timezone'))->toDateTimeString(),
            'contact_name' => $sender ? $this->displayName($sender) : null,
            'contact_username' => $sender['username'] ?? null,
            'raw_madeline' => $message,
        ];
    }

    private function isPrivatePeer(mixed $value): bool
    {
        if (is_int($value) || is_string($value)) {
            return true;
        }

        return is_array($value) && isset($value['user_id']);
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
        $name = trim(implode(' ', array_filter([
            $user['first_name'] ?? null,
            $user['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : ($user['username'] ?? null);
    }

    private function backfillContactProfiles(mixed $client): void
    {
        if (! method_exists($client, 'getInfo')) {
            return;
        }

        $limit = (int) config('services.telegram_support.profile_backfill_limit', 20);
        if ($limit <= 0) {
            return;
        }

        TelegramSupportContact::query()
            ->whereNotNull('telegram_user_id')
            ->where(function ($query) {
                $query->whereNull('name')->orWhereNull('username');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (TelegramSupportContact $contact) use ($client): void {
                try {
                    $profile = $this->extractUserProfile($client->getInfo((int) $contact->telegram_user_id));
                } catch (Throwable $e) {
                    Log::debug('Telegram support contact profile backfill skipped', [
                        'telegram_user_id' => $contact->telegram_user_id,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                if (! $profile) {
                    return;
                }

                $contact->fill([
                    'name' => $contact->name ?: $this->displayName($profile),
                    'username' => $contact->username ?: ($profile['username'] ?? null),
                ])->save();
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractUserProfile(mixed $info): ?array
    {
        if (! is_array($info)) {
            return null;
        }

        foreach (['User', 'user', 'Chat', 'chat'] as $key) {
            if (isset($info[$key]) && is_array($info[$key])) {
                return $info[$key];
            }
        }

        return isset($info['id']) ? $info : null;
    }

    private function supportAccount(string $accountName = 'support'): TelegramSupportAccount
    {
        return TelegramSupportAccount::firstOrCreate(
            ['name' => $accountName],
            [
                'session_path' => config('services.telegram_support.session'),
                'api_id' => config('services.telegram_support.api_id'),
                'is_enabled' => (bool) config('services.telegram_support.enabled'),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function updateSyncState(TelegramSupportAccount $account, array $messages): void
    {
        $state = $account->sync_state ?? [];
        $state['peers'] ??= [];

        foreach ($messages as $message) {
            $peerKey = (string) $message['telegram_chat_id'];
            $current = $state['peers'][$peerKey] ?? [];
            $messageId = (int) $message['telegram_message_id'];

            if ($messageId >= (int) ($current['last_message_id'] ?? 0)) {
                $state['peers'][$peerKey] = [
                    'last_message_id' => $messageId,
                    'last_sent_at' => $message['sent_at'],
                ];
            }
        }

        $account->forceFill([
            'sync_state' => $state,
            'last_successful_sync_at' => now(),
            'last_sync_error' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function finish(
        TelegramSupportAccount $account,
        array $result,
        bool $successful = false,
        array $messages = [],
    ): array {
        $account->forceFill([
            'session_path' => config('services.telegram_support.session'),
            'api_id' => config('services.telegram_support.api_id'),
            'is_enabled' => (bool) config('services.telegram_support.enabled'),
            'last_synced_at' => now(),
            'last_successful_sync_at' => $successful ? now() : $account->last_successful_sync_at,
            'last_sync_error' => $successful ? null : $account->last_sync_error,
        ])->save();

        Log::info('Telegram support sync finished', [
            'status' => $result['status'] ?? 'unknown',
            'synced' => $result['synced'] ?? 0,
            'dates' => $result['dates'] ?? [],
            'messages_seen' => count($messages),
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(TelegramSupportAccount $account, Throwable $e): array
    {
        $account->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => $e->getMessage(),
        ])->save();

        Log::error('Telegram support sync failed', [
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);

        return [
            'status' => 'error',
            'synced' => 0,
            'error' => $e->getMessage(),
        ];
    }

    private function extractTelegramId(mixed $value): ?int
    {
        if (is_int($value) || is_string($value)) {
            return (int) $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['user_id', 'chat_id', 'channel_id'] as $key) {
            if (isset($value[$key])) {
                return (int) $value[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertContact(
        TelegramSupportChat $chat,
        array $payload,
        CarbonImmutable $sentAt,
        ?User $linkedUser,
        string $direction,
    ): ?TelegramSupportContact {
        $telegramUserId = Arr::get($payload, 'telegram_user_id');

        if (! $telegramUserId && $direction !== 'incoming') {
            return null;
        }

        if (! $telegramUserId && empty($payload['contact_name']) && empty($payload['sender_name']) && empty($payload['contact_username']) && empty($payload['sender_username'])) {
            return null;
        }

        $contact = $telegramUserId
            ? TelegramSupportContact::firstOrNew(['telegram_user_id' => (int) $telegramUserId])
            : TelegramSupportContact::firstOrNew(['telegram_support_chat_id' => $chat->id]);

        $contact->fill([
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => $contact->linked_user_id ?: $linkedUser?->id,
            'name' => $payload['contact_name'] ?? $payload['sender_name'] ?? $contact->name,
            'username' => $payload['contact_username'] ?? $payload['sender_username'] ?? $contact->username,
            'first_seen_at' => $contact->first_seen_at
                ? min($contact->first_seen_at, $sentAt)
                : $sentAt,
            'first_inbound_at' => $direction === 'incoming'
                ? ($contact->first_inbound_at ? min($contact->first_inbound_at, $sentAt) : $sentAt)
                : $contact->first_inbound_at,
        ]);
        $contact->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{role: string, responder_type: ?string, responder_user_id: ?int, responder_marker: ?string, ai_state: ?string}
     */
    private function resolveResponder(array $payload, string $direction): array
    {
        $aiState = $payload['ai_state'] ?? null;
        if (in_array($aiState, ['suggested', 'sent'], true)) {
            return [
                'role' => 'ai',
                'responder_type' => 'ai',
                'responder_user_id' => null,
                'responder_marker' => $payload['responder_marker'] ?? null,
                'ai_state' => $aiState,
            ];
        }

        if ($direction === 'incoming') {
            return [
                'role' => 'user',
                'responder_type' => null,
                'responder_user_id' => null,
                'responder_marker' => null,
                'ai_state' => null,
            ];
        }

        $marker = $payload['responder_marker'] ?? null;
        $mapping = $marker
            ? SupportResponderMapping::where('marker_label', $marker)->where('is_active', true)->first()
            : null;
        $responderUserId = $payload['responder_user_id'] ?? $mapping?->user_id;
        $responderType = $payload['responder_type'] ?? $mapping?->responder_type ?? ($responderUserId ? 'human' : 'unknown');

        return [
            'role' => $responderType === 'human' ? 'human' : 'unknown',
            'responder_type' => $responderType,
            'responder_user_id' => $responderUserId ? (int) $responderUserId : null,
            'responder_marker' => $marker,
            'ai_state' => null,
        ];
    }
}
