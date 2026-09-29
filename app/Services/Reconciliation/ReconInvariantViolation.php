<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use RuntimeException;

/** H5445 (P3): нарушение правил журнала сверки (неизменяемость, переходы состояний). */
final class ReconInvariantViolation extends RuntimeException {}
