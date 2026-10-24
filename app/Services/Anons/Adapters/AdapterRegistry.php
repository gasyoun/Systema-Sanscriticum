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
            // H5935: senler — задокументированный manual lane, не «будущая работа»;
            // отказ остаётся fail-closed, но ведёт оператора в процедуру.
            if ($platform === 'senler') {
                throw new RuntimeException(
                    "No platform adapter registered for 'senler' (fail-closed by design). "
                    .'Senler ships as a documented manual lane — follow '
                    .'docs/ANONS_PUBLISHING_V2.md § "Senler manual lane (H5935)" (UI checklist, /ga/…-vk-… link smoke, journal row). '
                    .'An adapter needs a Senler API token and MG\'s explicit decision.'
                );
            }

            throw new RuntimeException("No platform adapter registered for '{$platform}' (fail-closed; vk adapters are future work).");
        }

        return $adapter;
    }

    /** @return list<string> */
    public function knownPlatforms(): array
    {
        return array_keys($this->adapters);
    }
}
