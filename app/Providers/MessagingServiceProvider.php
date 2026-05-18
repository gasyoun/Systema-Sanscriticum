<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Messaging\DeliveryChannelManager;
use App\Services\Messaging\MaxDeliveryChannel;
use App\Services\Messaging\SocialChannelParser;
use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\Messaging\VkDeliveryChannel;
use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons — конструкторы читают MarketingSetting один раз на запрос.
        $this->app->singleton(TelegramDeliveryChannel::class);
        $this->app->singleton(VkDeliveryChannel::class);
        $this->app->singleton(MaxDeliveryChannel::class);
        $this->app->singleton(DeliveryChannelManager::class);
        $this->app->singleton(SocialChannelParser::class);
    }
}
