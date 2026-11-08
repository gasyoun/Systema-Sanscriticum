<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\VkTokenManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Принять VK ID токен (строки KEY=VALUE в формате vk_poll.env) из stdin —
 * значения не попадают ни в argv, ни в историю шелла, ни в вывод.
 *
 *   ssh mac 'grep -E "^VK_(APP_ID|ACCESS_TOKEN|REFRESH_TOKEN|DEVICE_ID)=" ~/.claude/secrets/vk_poll.env' \
 *     | ssh prod 'cd /var/www/... && php artisan vk:token-import'
 *
 * Перед этим выключить Mac-агент ru.samskrte.vk-token-refresh: refresh
 * ротирует refresh_token, владелец у пары должен быть один.
 */
class VkTokenImportCommand extends Command
{
    protected $signature = 'vk:token-import';

    protected $description = 'Import the VK ID user token for content-calendar crossposting from stdin (KEY=VALUE lines)';

    public function handle(VkTokenManager $manager): int
    {
        $vars = [];
        foreach (preg_split('/\r\n|\n|\r/', (string) stream_get_contents(STDIN)) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z_]+)\s*=\s*(.*)$/', $line, $m)) {
                $vars[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        try {
            $token = $manager->import($vars);
        } catch (Throwable $e) {
            $this->error('Импорт не удался: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'VK-токен принят и обновлён: user_id=%s scope="%s" expires_at=%s',
            $token->vk_user_id ?? '?',
            $token->scope ?? '?',
            $token->expires_at?->toDateTimeString() ?? '?',
        ));

        return self::SUCCESS;
    }
}
