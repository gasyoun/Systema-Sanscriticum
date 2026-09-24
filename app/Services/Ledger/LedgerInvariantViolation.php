<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use DomainException;

/** H5443: нарушен инвариант денежного ядра (сервис или триггер БД «ledger: …»). */
class LedgerInvariantViolation extends DomainException {}
