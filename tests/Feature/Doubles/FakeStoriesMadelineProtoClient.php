<?php

declare(strict_types=1);

namespace Tests\Feature\Doubles;

use RuntimeException;

/**
 * Тест-дубль MadelineProto\API для сториз-лейна (H3964): умеет
 * stories.sendStory / stories.deleteStory и upload(). Формы вызова
 * повторяет строго: sendStory принимает ПЛОСКИЙ массив параметров,
 * upload() — абсолютный путь.
 *
 * $floodAfter — имитация дневного лимита user-сториз: после N успешных
 * отправок sendStory бросает FLOOD (как RPCErrorException живого клиента).
 */
class FakeStoriesMadelineProtoClient
{
    /** @var array<int, array<string, mixed>> */
    public static array $sentStories = [];

    /** @var array<int, array<string, mixed>> */
    public static array $deletedStories = [];

    /** @var array<int, string> */
    public static array $uploads = [];

    public static int $nextStoryId = 4210;

    public static int $constructions = 0;

    public static int $startCalls = 0;

    /** Сколько отправок пройти до FLOOD (null = лимита нет). */
    public static ?int $floodAfter = null;

    public object $stories;

    public function __construct(string $session, mixed $settings)
    {
        self::$constructions++;
        self::$startCalls++;

        $this->stories = new class
        {
            /**
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            public function sendStory(array $params): array
            {
                if (FakeStoriesMadelineProtoClient::$floodAfter !== null
                    && count(FakeStoriesMadelineProtoClient::$sentStories) >= FakeStoriesMadelineProtoClient::$floodAfter) {
                    throw new RuntimeException('FLOOD_PREMIUM_FREQ_X (retry after 43200 seconds)');
                }

                FakeStoriesMadelineProtoClient::$sentStories[] = $params;

                return [
                    '_' => 'updates',
                    'updates' => [
                        [
                            '_' => 'updateStory',
                            'story' => ['_' => 'storyItem', 'id' => FakeStoriesMadelineProtoClient::$nextStoryId++],
                        ],
                    ],
                ];
            }

            /**
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            public function deleteStories(array $params): array
            {
                FakeStoriesMadelineProtoClient::$deletedStories[] = $params;

                return ['_' => 'vector', 'count' => count($params['id'] ?? [])];
            }
        };
    }

    public function start(): void {}

    public function upload(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("upload(): file not found {$file}");
        }

        self::$uploads[] = $file;

        return ['_' => 'inputFile', 'id' => count(self::$uploads), 'name' => basename($file)];
    }

    public static function reset(): void
    {
        self::$sentStories = [];
        self::$deletedStories = [];
        self::$uploads = [];
        self::$nextStoryId = 4210;
        self::$constructions = 0;
        self::$startCalls = 0;
        self::$floodAfter = null;
    }
}
