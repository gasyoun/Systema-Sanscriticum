<?php

declare(strict_types=1);

namespace App\Services\Payout;

/**
 * H5444: запись расчётных пакетов выключена флагом features.money_payout_packages (P2 не переключает читателей без отдельного ops-шага).
 */
final class PayoutWritesDisabled extends \RuntimeException {}
