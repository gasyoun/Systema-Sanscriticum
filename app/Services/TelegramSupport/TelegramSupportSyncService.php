<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportResponderMapping;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\HomeworkPauseNoteRecorder;
use App\Services\Support\PendingSupportReplyDrainer;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Support\SupportOutgoingAttribution;
use App\Services\Support\TechnicalIssueRouter;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineSessionContext;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncPhase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TelegramSupportSyncService
{
    private static bool $loggedMissingTelegramIdColumn = false;

    /**
     * Один MadelineProto-клиент на ВЕСЬ заход (чтение + досыл ответов).
     *
     * Каждый `new API()` создаёт новый глобальный Logger, а тот в режиме
     * FILE_LOGGER — фоновый Amp-фибер pipe(), навсегда оседающий в статическом
     * Logger::$closePromises. На shutdown Logger::finalize() делает await() по
     * всем пайпам внутри register_shutdown_function, где Revolt уже не может
     * возобновить фибер, — «Event loop terminated without resuming the current
     * suspension (fiber deadlock)». Именно так до 15-08-2026 не доставился НИ
     * ОДИН ответ куратора: deliverMessage() открывал нового клиента на каждое
     * сообщение. Read-only заход с одним клиентом завершается чисто каждую
     * минуту — этот кеш и держит инвариант «один клиент на процесс».
     *
     * Кеш живёт на экземпляре, а сервис не singleton — то есть «на заход» по
     * построению. НЕ делайте сервис singleton'ом, не пересмотрев это.
     */
    private ?object $client = null;

    public function __construct(
        private readonly SupportDailyRollupAggregator $aggregator,
        private readonly SupportContactUserAutoLinker $autoLinker,
        private readonly SupportConversationManager $conversations,
        private readonly TechnicalIssueRouter $techRouter,
        private readonly MadelineSessionReaper $reaper,
        private readonly HomeworkPauseNoteRecorder $homeworkPauseNotes,
        private readonly SupportDmAutoReply $dmAutoReply,
        private readonly SupportOutgoingAttribution $outgoingAttribution,
    ) {}

    /**
     * @param  string  $accountName  H3380: какой telegram_support_accounts рядок
     *                               открываем. Контекст сессии уже переключён
     *                               командой ({@see MadelineSessionContext});
     *                               имя нужно здесь только для записи курсоров
     *                               в правильный рядок.
     */
    public function sync(string $accountName = 'support'): array
    {
        if (! config('services.telegram_support.enabled')) {
            Log::info('Telegram support sync skipped', ['status' => 'disabled']);

            return ['status' => 'disabled', 'synced' => 0];
        }

        $account = $this->supportAccount($accountName);

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
                    'delivered' => $this->drainPendingReplies($account),
                ], true, []);
            }

            $result = $this->syncNormalizedMessages($messages, $account->name);
            $this->updateSyncState($account->refresh(), $messages);
            $result['delivered'] = $this->drainPendingReplies($account);

            return $this->finish($account, $result, true, $messages);
        } catch (Throwable $e) {
            // Ветки для таймаута здесь БОЛЬШЕ НЕТ (H1915). Watchdog не бросает
            // исключение — оно не переживало 76 блоков `catch (Throwable)` внутри
            // MadelineProto и молча терялось, а одноразовый pcntl_alarm после
            // этого уже не срабатывал (заход 28.07.2026: 10 470 с и код 0).
            // Теперь он убирает за собой и завершает процесс сам, так что сюда
            // управление на таймауте не доходит в принципе.
            // Кончились файловые дескрипторы: демон сессии почти наверняка
            // осиротел, а автозагрузчик уже не может подтянуть классы — на этом
            // же исключении ломается и распознавание мёртвого IPC (оно ищет
            // Amp-обёртки, которые PHP не сумел загрузить). Отдельная ветка
            // перед IPC-веткой, иначе заход молча уходит в общий fail().
            if ($this->isFileDescriptorExhaustion($e)) {
                return $this->recoverFromFdExhaustion($account, $e);
            }

            // Мёртвый IPC-канал — отдельная ветка: убить зависший демон и почистить
            // сокет, но НЕ ретраить в этом же процессе (он уже держит мёртвый IPC
            // в памяти — повтор бесполезен). Восстановится следующий свежий запуск.
            if ($this->isMadelineIpcDead($e)) {
                return $this->recoverFromDeadIpc($account, $e);
            }

            return $this->fail($account, $e);
        }
    }

    /**
     * Разослать ответы куратора, ждущие доставки.
     *
     * Живёт в заходе синка, а не в очереди: MadelineProto не выживает в воркере
     * Horizon (`Amp\SignalException` → fiber deadlock), и прежний джоб
     * DeliverSupportReply не доставил ни одного сообщения за всё время. Здесь мы
     * в короткоживущем CLI-процессе, где MTProto работает каждую минуту.
     *
     * Падение досыла не должно ронять сам синк: заход уже прошёл успешно, и
     * терять его результат из-за одного неотправленного ответа нельзя.
     *
     * @return int сколько ответов ушло за этот заход
     */
    private function drainPendingReplies(TelegramSupportAccount $account): int
    {
        try {
            MadelineSyncPhase::mark('drain_pending');

            // H3380: дрен строго по аккаунту захода. Pending-ответы чужой сессии
            // через эту сессию не доставляются (peer может быть неизвестен) — их
            // заберёт свой telegram-support:sync --account=...
            return app(PendingSupportReplyDrainer::class)->drain($this, $account->id)['delivered'];
        } catch (Throwable $e) {
            Log::warning('Досыл ответов куратора сорвался, заход синка это не отменяет', [
                'error' => $e->getMessage(),
            ]);

            return 0;
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
            // Только AUTH_RESTART чиним повтором в этом же процессе (свежий new API()
            // переавторизуется). Мёртвый IPC сюда НЕ попадает — он обрабатывается на
            // верхнем уровне sync(), т.к. in-process retry против него бесполезен.
            if (! $this->isMadelineAuthRestart($e)) {
                throw $e;
            }

            Log::warning('Telegram support sync restarting MadelineProto auth flow after AUTH_RESTART');

            // Повтор обязан идти со СВЕЖИМ клиентом (прежняя семантика ретрая).
            // На проде AUTH_RESTART может прилететь и ПОСЛЕ успешного start()
            // (из getHistory) — тогда в кеше отравленный клиент. Обнуление
            // безопасно: __destruct реального API лишь регистрирует
            // async-деструктор, await по нему — только на shutdown.
            $this->client = null;

            return $this->fetchIncrementalMadelineMessages($account->refresh(), $clientClass);
        }
    }

    /**
     * Восстановление после «мёртвого» IPC-канала: убить зависший демон сессии и
     * снести осиротевший сокет (полная авто-версия ручного `pkill madeline + rm
     * *.ipc`), затем аккуратно завершить запуск — следующий свежий процесс поднимет
     * демон заново. НЕ ретраим здесь: текущий процесс уже держит мёртвый IPC.
     *
     * @return array<string, mixed>
     */
    private function recoverFromDeadIpc(TelegramSupportAccount $account, Throwable $e): array
    {
        $killed = $this->reaper->killDaemons();
        $removed = $this->reaper->clearIpcArtifacts();

        Log::warning('Telegram support sync: dead MadelineProto IPC — reset daemon, will reconnect next run', [
            'killed_processes' => $killed,
            'removed_files' => $removed,
            'error' => $e->getMessage(),
        ]);

        $account->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => 'IPC channel dead; daemon reset, reconnecting next run',
        ])->save();

        return ['status' => 'session_recovering', 'synced' => 0];
    }

    /**
     * Восстановление после исчерпания файловых дескрипторов (EMFILE). Точка
     * отказа не в нашем коде: amphp держит по сокету на соединение, и когда
     * дескрипторы кончаются, падает даже `include()` автозагрузчика — в логах
     * это выглядит как «include(.../revolt/event-loop/.../UncaughtThrowable.php):
     * Failed to open stream: Too many open files», хотя исходное исключение было
     * другим. Делаем то же, что при мёртвом IPC (сбрасываем демона сессии), но
     * пишем оператору ЧЕСТНУЮ причину: без поднятия LimitNOFILE у cron/supervisor
     * следующий заход упрётся в тот же потолок.
     *
     * @return array<string, mixed>
     */
    private function recoverFromFdExhaustion(TelegramSupportAccount $account, Throwable $e): array
    {
        $killed = $this->reaper->killDaemons();
        $removed = $this->reaper->clearIpcArtifacts();

        Log::error('Telegram support sync: out of file descriptors — reset daemon, raise LimitNOFILE', [
            'killed_processes' => $killed,
            'removed_files' => $removed,
            'error' => $e->getMessage(),
        ]);

        $account->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => 'Too many open files: закончились файловые дескрипторы, демон сессии сброшен. Поднимите LimitNOFILE у cron/supervisor.',
        ])->save();

        return ['status' => 'fd_exhausted', 'synced' => 0];
    }

    private function isMadelineAuthRestart(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'AUTH_RESTART');
    }

    /**
     * Признак «мёртвого» IPC-канала MadelineProto: демон сессии умер, а его сокет
     * остался, поэтому клиент бьёт в несуществующий контекст. Ловим и голый
     * Amp\Ipc\Sync\ChannelException, и его Revolt-обёртку (UncaughtThrowable),
     * разматывая цепочку previous.
     */
    private function isMadelineIpcDead(Throwable $e): bool
    {
        $current = $e;

        do {
            $message = $current->getMessage();
            if (str_contains($message, 'Did the context die?')
                || str_contains($message, 'Sending on the channel failed')
                || str_contains($message, 'ChannelException')) {
                return true;
            }
        } while ($current = $current->getPrevious());

        return false;
    }

    /**
     * Признак исчерпания файловых дескрипторов процесса (EMFILE), по всей цепочке
     * previous. Хватает одного маркера: PHP дописывает «Too many open files» и в
     * провал автозагрузки, в который EMFILE вырождается первым делом
     * («include(.../UncaughtThrowable.php): Failed to open stream: Too many open
     * files» — ровно эта строка легла в last_sync_error на проде 27.07.2026).
     * Проверять сам «Failed to open stream» нельзя: под него попал бы любой
     * отсутствующий файл.
     */
    private function isFileDescriptorExhaustion(Throwable $e): bool
    {
        $current = $e;

        do {
            if (str_contains($current->getMessage(), 'Too many open files')) {
                return true;
            }
        } while ($current = $current->getPrevious());

        return false;
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

        // После авто-линка повторно прогоняем recent incoming без треда — sender мог
        // только что получить linked_user_id.
        if (($linkResult['linked'] ?? 0) > 0) {
            $this->rerouteUnlinkedIncoming();
        }

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
        $chatType = (string) ($payload['chat_type'] ?? $chat->type ?? 'private');
        $isPrivate = $chatType === 'private';

        $chat->fill([
            // Multi-user group: не цеплять linked_user_id к «последнему отправителю».
            'linked_user_id' => $isPrivate
                ? ($chat->linked_user_id ?: $linkedUser?->id)
                : $chat->linked_user_id,
            'type' => $chatType ?: ($chat->type ?? 'private'),
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

        // reply-to-bot: если reply_to_msg_id — наше исходящее в этом чате.
        if ($direction === 'incoming' && empty($payload['reply_to_bot'])) {
            $replyToId = (int) ($payload['reply_to_msg_id'] ?? 0);
            if ($replyToId > 0 && $this->isOutgoingSupportMessage($chatId, $replyToId)) {
                $payload['reply_to_bot'] = true;
            }
        }

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

        $linkedUserId = $contact?->linked_user_id
            ?: ($isPrivate ? $chat->linked_user_id : null)
            ?: $linkedUser?->id;

        if ($direction === 'incoming') {
            $this->techRouter->handleIncoming($message, $payload, $linkedUserId ? (int) $linkedUserId : null, $chatType ?: 'private');

            $this->dmAutoReply->handle($message, $linkedUserId ? (int) $linkedUserId : null, $chatType ?: 'private');

            // H2320: «пауза по ДЗ» → users.note when student is linked.
            if ($linkedUserId) {
                $text = trim((string) ($payload['text'] ?? $message->text ?? ''));
                if ($text !== '') {
                    $user = User::query()->find((int) $linkedUserId);
                    if ($user) {
                        $this->homeworkPauseNotes->recordIfMatches($user, $text, 'telegram-support');
                    }
                }
            }
        } elseif ($linkedUserId && $isPrivate) {
            // Исходящие в ЛС по-прежнему цепляем к треду (история ответов).
            $this->conversations->recordMessage((int) $linkedUserId, $message, $message->sent_at);
        }

        return $message;
    }

    private function isOutgoingSupportMessage(int $telegramChatId, int $telegramMessageId): bool
    {
        return TelegramSupportMessage::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->where('telegram_message_id', $telegramMessageId)
            ->where('direction', 'outgoing')
            ->exists();
    }

    /**
     * После auto-link: входящие без support_conversation_id, у которых contact
     * уже linked — повторно отдать в TechnicalIssueRouter.
     */
    private function rerouteUnlinkedIncoming(): void
    {
        TelegramSupportMessage::query()
            ->where('direction', 'incoming')
            ->whereNull('support_conversation_id')
            ->whereNotNull('telegram_support_contact_id')
            ->whereHas('contact', fn ($q) => $q->whereNotNull('linked_user_id'))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->each(function (TelegramSupportMessage $message): void {
                $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
                $payload['direction'] = 'incoming';
                $payload['text'] = $message->text;
                $payload['telegram_chat_id'] = $message->telegram_chat_id;
                $payload['telegram_message_id'] = $message->telegram_message_id;
                $chatType = (string) ($payload['chat_type'] ?? $message->chat?->type ?? 'private');
                $linkedUserId = $message->contact?->linked_user_id;
                $this->techRouter->handleIncoming(
                    $message,
                    $payload,
                    $linkedUserId ? (int) $linkedUserId : null,
                    $chatType,
                );
            });
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
        $self = $this->resolveSelfIdentity($client);
        MadelineSyncPhase::mark('dialogs');
        $dialogs = $this->dialogsWithTechGroups($client, $this->limitedDialogs($client->getDialogIds()));
        $messages = [];
        $peerState = $account->sync_state['peers'] ?? [];

        foreach ($dialogs as $peer) {
            $peerId = $this->extractTelegramId($peer);
            $cursor = $peerId ? ($peerState[(string) $peerId] ?? []) : [];
            $minId = (int) ($cursor['last_message_id'] ?? 0);

            MadelineSyncPhase::mark('history:'.($peerId ?: 'unknown'));
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
            $chatsById = $this->chatsById($history['chats'] ?? []);

            foreach (($history['messages'] ?? []) as $message) {
                $normalized = $this->normalizeMadelineMessage($peer, $message, $usersById, $chatsById, $self);
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

    /**
     * Залогиненный MadelineProto-клиент — ОДИН на заход (см. докблок $client).
     *
     * Кеш присваивается только ПОСЛЕ успешного createClient(): если start()
     * бросил (AUTH_RESTART), кеш обязан остаться пустым, чтобы ретрай создал
     * свежий клиент. В recover-ветках (dead IPC / FD exhaustion) кеш сознательно
     * НЕ сбрасывается: они терминальные, процесс завершается сразу после них.
     */
    protected function openClient(string $clientClass): object
    {
        if ($this->client instanceof $clientClass) {
            return $this->client;
        }

        return $this->client = $this->createClient($clientClass);
    }

    /** Создать и залогинить клиент. Точка переопределения для тест-двойников. */
    protected function createClient(string $clientClass): object
    {
        // Абсолютный путь через фабрику: дефолт конфига — storage_path() (уже
        // абсолютный), оборачивать его в base_path() нельзя (задвоение пути).
        $session = MadelineClientFactory::sessionPath();
        File::ensureDirectoryExists(dirname($session));

        MadelineSyncPhase::mark('client_start');
        $client = new $clientClass($session, $this->madelineSettings($clientClass));
        $client->start();
        MadelineSyncPhase::mark('client_ready');

        return $client;
    }

    /**
     * Отправить исходящее сообщение через userbot в указанный чат. Возвращает
     * статус + реальный telegram_message_id при успехе. Гварды повторяют sync():
     * без enabled/creds/клиента — просто статус, без открытия клиента. Ошибки
     * самой отправки пробрасываются — их ловит и ретраит PendingSupportReplyDrainer.
     *
     * Клиент берётся из кеша захода (openClient), НЕ создаётся заново: второй
     * new API() в процессе — это второй Logger-пайп и fiber deadlock на
     * shutdown, из-за которого доставка не работала никогда (см. $client).
     *
     * @return array{status: string, telegram_message_id?: int|null}
     */
    public function deliverMessage(int $chatId, string $text, ?int $replyToMsgId = null): array
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

        $params = [
            'peer' => $chatId,
            'message' => $text,
        ];
        if ($replyToMsgId !== null && $replyToMsgId > 0) {
            // Плоский reply_to_msg_id MadelineProto 8.x отвергает на входе:
            // «reply_to_msg_id is deprecated, please use reply_to…» — на этом
            // 15-08-2026 (21:03) упали первые же отправки, дожившие до API.
            // Формат конструктора — как в его собственных high-level методах
            // (vendor: MTProtoTools/UpdateHandler.php, inputReplyToMessage).
            $params['reply_to'] = [
                '_' => 'inputReplyToMessage',
                'reply_to_msg_id' => $replyToMsgId,
            ];
        }

        $result = $this->openClient($clientClass)->messages->sendMessage($params);

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
     * @param  array<int, array<string, mixed>>  $chatsById
     * @param  array{id: ?int, username: ?string}  $self
     * @return array<string, mixed>|null
     */
    private function normalizeMadelineMessage(
        mixed $peer,
        array $message,
        array $usersById = [],
        array $chatsById = [],
        array $self = ['id' => null, 'username' => null],
    ): ?array {
        if (! isset($message['id']) || ! isset($message['date'])) {
            return null;
        }

        $text = $message['message'] ?? null;
        if ($text === null || $text === '') {
            return null;
        }

        $peerRef = $message['peer_id'] ?? $peer;
        $chatId = $this->extractTelegramId($peerRef);
        if ($chatId === null) {
            return null;
        }
        $chatType = $this->resolveChatType($peerRef, $chatId);
        $telegramUserId = $this->extractTelegramId($message['from_id'] ?? null);
        if (! $telegramUserId && empty($message['out']) && $chatType === 'private') {
            $telegramUserId = $chatId;
        }
        $sender = $telegramUserId ? ($usersById[$telegramUserId] ?? null) : null;
        $chatMeta = $chatsById[abs($chatId)] ?? $chatsById[$chatId] ?? null;
        $replyToMsgId = $this->extractReplyToMsgId($message);

        return [
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => (int) $message['id'],
            'telegram_user_id' => $telegramUserId,
            'direction' => ! empty($message['out']) ? 'outgoing' : 'incoming',
            'text' => $text,
            'sent_at' => CarbonImmutable::createFromTimestamp((int) $message['date'], config('app.timezone'))->toDateTimeString(),
            'contact_name' => $sender ? $this->displayName($sender) : null,
            'contact_username' => $sender['username'] ?? null,
            'chat_type' => $chatType,
            'chat_title' => $chatMeta['title'] ?? null,
            'chat_username' => $chatMeta['username'] ?? null,
            'reply_to_msg_id' => $replyToMsgId,
            'mentioned_bot' => $this->messageMentionsSelf($message, $self),
            'raw_madeline' => $message,
        ];
    }

    private function isPrivatePeer(mixed $value): bool
    {
        if (is_array($value)) {
            return isset($value['user_id']);
        }

        if (is_int($value) || is_string($value)) {
            return (int) $value > 0;
        }

        return false;
    }

    private function resolveChatType(mixed $peerRef, int $chatId): string
    {
        if (is_array($peerRef)) {
            if (isset($peerRef['user_id'])) {
                return 'private';
            }
            if (isset($peerRef['chat_id'])) {
                return 'group';
            }
            if (isset($peerRef['channel_id'])) {
                return 'supergroup';
            }
        }

        if ($chatId > 0) {
            return 'private';
        }

        // -100… → megagroup/channel peer id convention
        if ($chatId <= -1000000000000) {
            return 'supergroup';
        }

        return 'group';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractReplyToMsgId(array $message): ?int
    {
        $reply = $message['reply_to'] ?? null;
        if (is_array($reply) && isset($reply['reply_to_msg_id'])) {
            return (int) $reply['reply_to_msg_id'];
        }
        if (isset($message['reply_to_msg_id'])) {
            return (int) $message['reply_to_msg_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array{id: ?int, username: ?string}  $self
     */
    private function messageMentionsSelf(array $message, array $self): bool
    {
        $text = (string) ($message['message'] ?? '');
        $username = $self['username'] ?: config('services.telegram_support.username');
        $selfId = $self['id'] ?? null;

        if ($username) {
            $username = ltrim((string) $username, '@');
            if ($username !== '' && preg_match('/@'.preg_quote($username, '/').'\b/iu', $text)) {
                return true;
            }
        }

        foreach ($message['entities'] ?? [] as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $type = (string) ($entity['_'] ?? '');
            if ($type === 'messageEntityMentionName' && $selfId && (int) ($entity['user_id'] ?? 0) === (int) $selfId) {
                return true;
            }
            if ($type === 'messageEntityMention' && $username) {
                $offset = (int) ($entity['offset'] ?? 0);
                $length = (int) ($entity['length'] ?? 0);
                if ($length > 0) {
                    $mention = ltrim(mb_substr($text, $offset, $length), '@');
                    if (strcasecmp($mention, ltrim((string) $username, '@')) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array{id: ?int, username: ?string}
     */
    private function resolveSelfIdentity(object $client): array
    {
        try {
            if (! method_exists($client, 'getSelf')) {
                return ['id' => null, 'username' => config('services.telegram_support.username')];
            }
            $self = $client->getSelf();
            if (! is_array($self)) {
                return ['id' => null, 'username' => config('services.telegram_support.username')];
            }

            return [
                'id' => isset($self['id']) ? (int) $self['id'] : null,
                'username' => isset($self['username']) ? (string) $self['username'] : config('services.telegram_support.username'),
            ];
        } catch (Throwable) {
            return ['id' => null, 'username' => config('services.telegram_support.username')];
        }
    }

    /**
     * Top-N dialogs ∪ allowlist учебных групп (config tech_group_peers).
     *
     * @param  array<int, mixed>  $dialogs
     * @return array<int, mixed>
     */
    private function dialogsWithTechGroups(object $client, array $dialogs): array
    {
        $peers = config('services.telegram_support.tech_group_peers', []);
        if (! is_array($peers) || $peers === []) {
            return $dialogs;
        }

        $seen = [];
        foreach ($dialogs as $dialog) {
            $id = $this->extractTelegramId($dialog);
            if ($id !== null) {
                $seen[$id] = true;
            }
        }

        foreach ($peers as $peer) {
            $peer = is_string($peer) || is_int($peer) ? $peer : null;
            if ($peer === null || $peer === '') {
                continue;
            }
            $id = $this->extractTelegramId($peer);
            if ($id !== null && isset($seen[$id])) {
                continue;
            }
            // @username — оставляем как peer-строку; Madeline getHistory примет.
            $dialogs[] = is_numeric($peer) ? (int) $peer : $peer;
            if ($id !== null) {
                $seen[$id] = true;
            }
        }

        return $dialogs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chats
     * @return array<int, array<string, mixed>>
     */
    private function chatsById(array $chats): array
    {
        $indexed = [];
        foreach ($chats as $chat) {
            if (isset($chat['id'])) {
                $indexed[(int) $chat['id']] = $chat;
            }
        }

        return $indexed;
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

        $text = (string) ($payload['text'] ?? '');
        $marker = $this->outgoingAttribution->markerFromOutgoingText($text);
        if (($payload['responder_marker'] ?? null) && $marker !== SupportOutgoingAttribution::APPLE_MARKER) {
            $marker = (string) $payload['responder_marker'];
        }
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
