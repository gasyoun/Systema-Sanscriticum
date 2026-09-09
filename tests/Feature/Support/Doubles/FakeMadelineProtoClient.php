<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Doubles;

/**
 * Тест-дубль MadelineProto\API, умеющий И читать, И отправлять.
 *
 * До 15-08-2026 жили два двойника: читающий (inline внизу
 * TelegramSupportAnalyticsTest, недоступный другим сьютам) и шлющий
 * FakeMadelineApi (осиротел после удаления DeliverSupportReplyTest). Инвариант
 * «один клиент на весь заход синка, включая досыл ответов» проверяем только
 * двойником, который умеет обе стороны, — поэтому они объединены здесь.
 *
 * $constructions считает СОЗДАНИЯ объектов (не вызовы start()): ровно это и
 * есть инвариант — второй new API() в процессе добавляет второй Logger-пайп в
 * статический Logger::$closePromises, и shutdown падает в fiber deadlock.
 */
class FakeMadelineProtoClient
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $histories = [];

    /**
     * H4439: фикстура messages.getDialogs — дата-desc список диалогов
     * (['peer' => [...], ...]). Пусто → строится из ключей $histories
     * (совместимость со старыми тестами окна).
     *
     * @var list<array<string, mixed>>|null
     */
    public static ?array $dialogs = null;

    public static int $getDialogsCalls = 0;

    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $users = [];

    /** @var array<int, array<string, mixed>> */
    public static array $profiles = [];

    /** @var array<int, array<string, mixed>> */
    public static array $lastHistoryRequests = [];

    /** @var array<int, string> */
    public static array $startFailures = [];

    public static int $startCalls = 0;

    /** Сколько раз клиент СОЗДАВАЛИ за процесс — счётчик инварианта. */
    public static int $constructions = 0;

    /** @var array<int, array<string, mixed>> */
    public static array $sent = [];

    public static int $nextMessageId = 555;

    public object $messages;

    public function __construct(string $session, mixed $settings)
    {
        self::$constructions++;

        $this->messages = new class
        {
            /**
             * H4439: окно свежести — сырой messages.getDialogs (ответ
             * date-desc по спецификации MTProto).
             *
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            public function getDialogs(array $params = []): array
            {
                FakeMadelineProtoClient::$getDialogsCalls++;

                $limit = max(1, (int) ($params['limit'] ?? 20));
                $list = FakeMadelineProtoClient::$dialogs ?? array_map(
                    fn ($id): array => is_string($id) && str_starts_with($id, '-')
                        ? ['peer' => ['_' => 'peerChannel', 'channel_id' => abs((int) $id)]]
                        : ['peer' => ['_' => 'peerUser', 'user_id' => (int) $id]],
                    array_keys(FakeMadelineProtoClient::$histories),
                );

                return [
                    'dialogs' => array_slice($list, 0, $limit),
                    'messages' => [],
                    'users' => [],
                    'chats' => [],
                ];
            }

            /**
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            public function getHistory(array $params): array
            {
                FakeMadelineProtoClient::$lastHistoryRequests[] = $params;
                $peer = (int) $params['peer'];
                $minId = (int) $params['min_id'];

                return [
                    'messages' => collect(FakeMadelineProtoClient::$histories[$peer] ?? [])
                        ->filter(fn (array $message) => (int) $message['id'] > $minId)
                        ->values()
                        ->all(),
                    'users' => FakeMadelineProtoClient::$users[$peer] ?? [],
                ];
            }

            /**
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            public function sendMessage(array $params): array
            {
                // Повторяем валидацию реального клиента (Connection.php:520-522,
                // MadelineProto 8.x): плоский reply_to_msg_id отвергается на
                // входе. Без неё двойник пропускал бы формат, на котором
                // 15-08-2026 упали первые же отправки, дожившие до API.
                if (isset($params['reply_to_msg_id'])) {
                    throw new \RuntimeException('reply_to_msg_id is deprecated, please use reply_to or the new sendMessage/sendVideo/etc... methods instead!');
                }

                FakeMadelineProtoClient::$sent[] = $params;

                return [
                    '_' => 'updates',
                    'updates' => [
                        ['_' => 'updateMessageID', 'id' => FakeMadelineProtoClient::$nextMessageId],
                    ],
                ];
            }
        };
    }

    public static function reset(): void
    {
        self::$histories = [];
        self::$dialogs = null;
        self::$getDialogsCalls = 0;
        self::$users = [];
        self::$profiles = [];
        self::$lastHistoryRequests = [];
        self::$startFailures = [];
        self::$startCalls = 0;
        self::$constructions = 0;
        self::$sent = [];
        self::$nextMessageId = 555;
    }

    public function start(): void
    {
        self::$startCalls++;

        if (self::$startFailures !== []) {
            throw new \RuntimeException(array_shift(self::$startFailures));
        }
    }

    /**
     * @return array<int>
     */
    public function getDialogIds(): array
    {
        return array_keys(self::$histories);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInfo(int $id): array
    {
        return self::$profiles[$id] ?? [];
    }
}
