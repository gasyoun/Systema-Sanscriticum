<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Messaging\Contracts\DeliveryChannel;
use InvalidArgumentException;

final class DeliveryChannelManager
{
    /** @var array<string, DeliveryChannel> */
    private array $channels;

    public function __construct(
        TelegramDeliveryChannel $telegram,
        VkDeliveryChannel $vk,
        MaxDeliveryChannel $max,
    ) {
        $this->channels = [
            'telegram' => $telegram,
            'vk' => $vk,
            'max' => $max,
        ];
    }

    public function get(string $name): DeliveryChannel
    {
        return $this->channels[$name]
            ?? throw new InvalidArgumentException("Unknown delivery channel: {$name}");
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }
}
