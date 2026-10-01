<?php

declare(strict_types=1);

namespace App\Services\Payout;

/**
 * H5444: тот же стабильный ключ описывает ДРУГОЙ факт — это не повтор, а расхождение; молча перезаписать его нельзя.
 */
final class PayoutReplayConflict extends \RuntimeException {}
