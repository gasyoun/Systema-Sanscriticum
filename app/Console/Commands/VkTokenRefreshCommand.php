<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VkUserToken;
use App\Services\Content\VkTokenManager;
use Illuminate\Console\Command;

/**
 * Плановое обновление часового VK ID токена кросспостинга (каждые 15 минут,
 * фактический refresh — когда до истечения < 35 мин). Нет токена — no-op.
 */
class VkTokenRefreshCommand extends Command
{
    protected $signature = 'vk:token-refresh';

    protected $description = 'Refresh the VK ID user token used for content-calendar crossposting';

    public function handle(VkTokenManager $manager): int
    {
        if (VkUserToken::current() === null) {
            $this->line('VK-токен не импортирован — пропуск.');

            return self::SUCCESS;
        }

        $alive = $manager->refreshIfNeeded();
        $token = VkUserToken::current();
        $this->info(sprintf(
            'vk:token-refresh: %s, expires_at=%s, scope="%s"%s',
            $alive ? 'ok' : 'НЕ ДЕЙСТВУЕТ',
            $token?->expires_at?->toDateTimeString() ?? '?',
            $token?->scope ?? '?',
            $token?->last_error ? ', last_error='.$token->last_error : '',
        ));

        return $alive ? self::SUCCESS : self::FAILURE;
    }
}
