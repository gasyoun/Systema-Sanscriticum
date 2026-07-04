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
     * Start (or resume) the shared MadelineProto client on the support session.
     */
    public function open(?string $clientClass = null): object
    {
        $clientClass ??= (string) config('services.telegram_support.client_class');
        $session = (string) config('services.telegram_support.session');

        File::ensureDirectoryExists(dirname(base_path($session)));

        $client = new $clientClass($session, $this->settings());
        $client->start();

        return $client;
    }

    private function settings(): object
    {
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
