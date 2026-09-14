<?php

declare(strict_types=1);

namespace App\Services\BankStatements;

use RuntimeException;

/**
 * Выписка не разобралась — импорт обязан REFUSE (H4200).
 *
 * Бросается парсерами BankStatements на любом непонятном входе: неизвестная
 * шапка, отсутствие обязательных колонок, неразбираемая дата/сумма. Молча
 * пропустить деньги нельзя — пусть отчёт dry-run честно падает, оператор
 * смотрит на реальный формат файла и расширяет алиасы.
 */
class UnparseableStatementException extends RuntimeException {}
