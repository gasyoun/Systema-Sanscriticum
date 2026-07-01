<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Doubles;

/**
 * Тест-дубль MadelineProto\API: конструктор (session, settings) + start() +
 * магическое свойство messages->sendMessage(). Записывает отправленное и
 * возвращает Updates c updateMessageID, как настоящий клиент.
 */
class FakeMadelineApi
{
    /** @var array<int, array<string, mixed>> */
    public static array $sent = [];

    public static int $nextMessageId = 555;

    public FakeMadelineMessages $messages;

    public function __construct($session = null, $settings = null)
    {
        $this->messages = new FakeMadelineMessages;
    }

    public static function reset(): void
    {
        self::$sent = [];
        self::$nextMessageId = 555;
    }

    public function start(): void {}
}

class FakeMadelineMessages
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function sendMessage(array $params): array
    {
        FakeMadelineApi::$sent[] = $params;

        return [
            '_' => 'updates',
            'updates' => [
                ['_' => 'updateMessageID', 'id' => FakeMadelineApi::$nextMessageId],
            ],
        ];
    }
}
