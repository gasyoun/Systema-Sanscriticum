<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineSessionContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Интерактивный вход в сессию именованного support-аккаунта (H3380).
 *
 * Запускается ЧЕЛОВЕКОМ на проде один раз на аккаунт: MadelineProto спросит
 * телефон → код из Telegram → пароль 2FA прямо в CLI. Успешный start()
 * пишет session-каталог; команда фиксирует его путь в строке аккаунта и
 * включает её — дальше telegram-support:sync --account=X работает сам.
 *
 * Для дефолтного аккаунта 'support' команда не нужна: его сессия уже живёт
 * в TELEGRAM_SUPPORT_SESSION.
 */
class LoginTelegramSupportAccount extends Command
{
    protected $signature = 'telegram-support:login
        {--account= : Имя telegram_support_accounts-строки (по умолчанию запрашивается)}';

    protected $description = 'Интерактивный вход MadelineProto для именованного support-аккаунта (телефон + код + 2FA) и включение его строки.';

    public function handle(MadelineClientFactory $factory): int
    {
        if (! config('services.telegram_support.api_id') || ! config('services.telegram_support.api_hash')) {
            $this->error('TELEGRAM_SUPPORT_API_ID / API_HASH не заданы — логин невозможен.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('account') ?: $this->ask('Имя аккаунта (строка telegram_support_accounts)'));

        if ($name === '' || $name === 'support') {
            $this->error('Для дефолтного support-аккаунта эта команда не используется.');

            return self::FAILURE;
        }

        /** @var TelegramSupportAccount|null $account */
        $account = TelegramSupportAccount::query()->where('name', $name)->first();

        if ($account !== null && $this->confirm("Аккаунт «{$name}» уже существует".($account->is_enabled ? ' и включён' : '').'. Перелогиниться?', false) === false && (string) $account->session_path !== '') {
            $this->line('Отменено.');

            return self::SUCCESS;
        }

        // Путь по умолчанию — отдельный каталог на аккаунт, чтобы IPC-артефакты
        // и safe.php не пересекались с основной сессией.
        $relativePath = 'storage/app/telegram-support/'.$name.'/session.madeline';
        $absolutePath = base_path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));

        MadelineSessionContext::useSession($absolutePath);

        $this->info('Открываю сессию: '.$absolutePath);
        $this->line('Введите телефон аккаунта, затем код из Telegram (и пароль 2FA, если включён).');

        try {
            $factory->open();
        } catch (\Throwable $e) {
            $this->error('Логин не удался: '.$e->getMessage());

            return self::FAILURE;
        }

        TelegramSupportAccount::updateOrCreate(
            ['name' => $name],
            [
                'session_path' => $relativePath,
                'api_id' => config('services.telegram_support.api_id'),
                'is_enabled' => true,
            ],
        );

        $this->info("Готово: сессия «{$name}» записана и строка аккаунта включена.");
        $this->line('Проверка: php artisan telegram-support:sync --account='.$name);

        return self::SUCCESS;
    }
}
