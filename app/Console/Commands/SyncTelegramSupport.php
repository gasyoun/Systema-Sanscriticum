<?php

namespace App\Console\Commands;

use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncTelegramSupport extends Command
{
    protected $signature = 'telegram-support:sync {--payload= : JSON file with normalized Telegram messages for local import}';

    protected $description = 'Sync Telegram support-account messages and rebuild daily support analytics.';

    public function handle(TelegramSupportSyncService $sync): int
    {
        $payloadPath = $this->option('payload');

        if ($payloadPath) {
            if (! File::exists($payloadPath)) {
                $this->error("Payload file not found: {$payloadPath}");

                return self::FAILURE;
            }

            $payload = json_decode(File::get($payloadPath), true);
            if (! is_array($payload)) {
                $this->error('Payload must be a JSON array.');

                return self::FAILURE;
            }

            $result = $sync->syncNormalizedMessages($payload);
        } else {
            $result = $sync->sync();
        }

        $this->info('Telegram support sync: '.$result['status'].'; synced='.$result['synced']);

        return self::SUCCESS;
    }
}
