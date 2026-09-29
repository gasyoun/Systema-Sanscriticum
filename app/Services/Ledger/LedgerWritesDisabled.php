<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use RuntimeException;

/** H5443: запись в денежное ядро выключена флагом features.money_ledger_core (P1 — только теневой прогон). */
final class LedgerWritesDisabled extends RuntimeException
{
    /** Страж моделей: прямой Model::create() мимо сервиса тоже упирается во флаг. */
    public static function guard(): void
    {
        if (! app(LedgerService::class)->writable()) {
            throw new self('Денежное ядро (H5443) выключено: features.money_ledger_core=false. В P1 запись идёт только в теневом прогоне.');
        }
    }
}
