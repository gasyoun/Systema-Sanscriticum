<?php

declare(strict_types=1);

namespace App\Services\Ledger;

/** H5443: тот же ключ повтора пришёл с другим содержанием — это не повтор, а конфликт. */
final class LedgerReplayConflict extends LedgerInvariantViolation {}
