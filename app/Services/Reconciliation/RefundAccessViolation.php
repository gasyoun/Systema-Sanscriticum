<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use InvalidArgumentException;

/** H5445 (P3, D10): возврат отвергнут правилом «частичный — только с блоками». */
final class RefundAccessViolation extends InvalidArgumentException {}
