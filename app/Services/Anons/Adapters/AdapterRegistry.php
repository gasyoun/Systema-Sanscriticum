<?php

declare(strict_types=1);

namespace App\Services\Anons\Adapters;

use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\Stories\StoryPublisher;
use RuntimeException;

/**
 * H5049 R9: реестр платформенных адаптеров. Неизвестная платформа
 * манифеста — fail-closed ошибка, не молчаливый пропуск.
 */
final class AdapterRegistry
{
    /** @var array<string, PlatformAdapter> */
    private array $adapters = [];

    public function __construct()
    {
        foreach ([
            new TelegramStoryAdapter(app(StoryPublisher::class)),
            new TelegramPostAdapter(app(TelegramDeliveryChannel::class)),
        ] as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(PlatformAdapter $adapter): void
    {
        $this->adapters[$adapter->platform()] = $adapter;
    }

    public function for(string $platform): PlatformAdapter
    {
        $adapter = $this->adapters[$platform] ?? null;
        if ($adapter === null) {
            throw new RuntimeException("No platform adapter registered for '{$platform}' (fail-closed; vk/senler adapters are future work).");
        }

        return $adapter;
    }

    /** @return list<string> */
    public function knownPlatforms(): array
    {
        return array_keys($this->adapters);
    }
}
