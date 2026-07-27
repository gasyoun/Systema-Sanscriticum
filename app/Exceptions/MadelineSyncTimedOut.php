<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Telegram\MadelineSyncWatchdog;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use RuntimeException;

/**
 * Заход MTProto-синка превысил потолок времени и был прерван watchdog'ом
 * (см. {@see MadelineSyncWatchdog}).
 *
 * Отдельный тип нужен, чтобы {@see TelegramSupportSyncService::sync()}
 * НЕ проглотил таймаут своим общим `catch (Throwable)` как рядовую ошибку синка:
 * зависание лечится не записью в `last_sync_error`, а завершением процесса и
 * сбросом демона сессии — этим занимается сама команда.
 */
class MadelineSyncTimedOut extends RuntimeException
{
    public function __construct(public readonly int $timeoutSeconds)
    {
        parent::__construct("MadelineProto sync exceeded {$timeoutSeconds}s watchdog timeout");
    }
}
