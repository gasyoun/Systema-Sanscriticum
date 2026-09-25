<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use RuntimeException;

/**
 * H5480: заголовок/кодировка выписки не опознаны. Отказ, а не молчаливый
 * пропуск строк: «разобрали 0 зачислений» выглядит как «зачислений не было».
 */
final class StatementFormatError extends RuntimeException {}
