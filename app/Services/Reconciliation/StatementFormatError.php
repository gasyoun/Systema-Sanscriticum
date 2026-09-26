<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use RuntimeException;

/**
 * H5480 (P3b): заголовок выписки не совпал ни с одной признанной формой, или
 * файл не декодируется. Отказ, а не тихий пропуск строк: молча пропущенная
 * выписка сделала бы день «покрытым» без денег.
 */
final class StatementFormatError extends RuntimeException {}
