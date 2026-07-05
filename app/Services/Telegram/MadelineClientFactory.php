<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;

/**
 * Opens the ONE shared logged-in MadelineProto client.
 *
 * There is exactly one MadelineProto session for the personal account (see
 * Uprava/docs/DECISIONS_telegram_harvester.md D1). Both the support sync
 * (telegram-support:sync) and the Track B harvester (telegram-harvest:sync)
 * MUST reuse this single session — a second parallel session on the same
 * account triggers AUTH_RESTART / auto-logout. Session + credentials are read
 * from services.telegram_support.* so the two commands literally share the
 * same session file.
 *
 * Operational constraint: never run the two sync commands concurrently.
 */
class MadelineClientFactory
{
    /** True when api credentials and a MadelineProto client class are present. */
    public function isConfigured(): bool
    {
        if (! config('services.telegram_support.api_id') || ! config('services.telegram_support.api_hash')) {
            return false;
        }

        $clientClass = (string) config('services.telegram_support.client_class');

        return $clientClass !== '' && class_exists($clientClass);
    }

    /**
     * Абсолютный путь к session-файлу. Дефолт конфига — storage_path() (уже
     * абсолютный), но из .env может прийти относительный — тогда от base_path.
     * Без этой развилки base_path(storage_path(...)) задваивал путь, а warning
     * от mkdir() эскалировался в исключение error-handler'ом MadelineProto.
     * Абсолютный путь также обязателен для самого клиента: session, переданная
     * относительной, разрешалась бы от CWD (у Horizon/cron он другой).
     */
    public static function sessionPath(): string
    {
        $session = (string) config('services.telegram_support.session');

        return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $session) === 1
            ? $session
            : base_path($session);
    }

    /**
     * Start (or resume) the shared MadelineProto client on the support session.
     */
    public function open(?string $clientClass = null): object
    {
        $clientClass ??= (string) config('services.telegram_support.client_class');
        $session = self::sessionPath();

        File::ensureDirectoryExists(dirname($session));

        $client = new $clientClass($session, $this->settings($clientClass));
        $client->start();

        return $client;
    }

    private function settings(string $clientClass): object
    {
        // Реальные Settings строим только для настоящего клиента: сам факт
        // загрузки классов MadelineProto ставит его глобальный error-handler,
        // который эскалирует warnings/deprecations в исключения — в тестах
        // (фейковый клиент) это роняет весь остальной сьют. Фейки настройки
        // не читают — им достаточно заглушки.
        if (! is_a($clientClass, 'danog\\MadelineProto\\API', true)) {
            return new \stdClass;
        }

        $settingsClass = 'danog\\MadelineProto\\Settings';
        $loggerClass = 'danog\\MadelineProto\\Logger';

        $settings = new $settingsClass;
        $settings->getAppInfo()
            ->setApiId((int) config('services.telegram_support.api_id'))
            ->setApiHash((string) config('services.telegram_support.api_hash'));
        $settings->getLogger()
            ->setType($loggerClass::FILE_LOGGER)
            ->setExtra(storage_path('logs/madelineproto.log'))
            ->setLevel($loggerClass::LEVEL_WARNING);

        return $settings;
    }
}
