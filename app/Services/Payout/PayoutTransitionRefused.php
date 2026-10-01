<?php

declare(strict_types=1);

namespace App\Services\Payout;

/**
 * H5444 (D13): переход конечного автомата пакета запрещён — назад нельзя, замороженный состав менять нельзя, отрицательный рублёвый итог не проводится.
 */
final class PayoutTransitionRefused extends \RuntimeException {}
