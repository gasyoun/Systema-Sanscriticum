<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use RuntimeException;

/** H5443: запись в денежное ядро выключена флагом features.money_ledger_core (P1 — только теневой прогон). */
final class LedgerWritesDisabled extends RuntimeException {}
