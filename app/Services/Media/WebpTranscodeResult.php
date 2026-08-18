<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Исход одной перекодировки. Отдельный объект, а не bool|string, потому что
 * вызывающему нужны сразу три вещи: новый путь, причина отказа и выигрыш по
 * весу для отчёта.
 */
final class WebpTranscodeResult
{
    private function __construct(
        public readonly bool $converted,
        public readonly string $source,
        public readonly ?string $target,
        public readonly string $reason,
        public readonly int $bytesBefore,
        public readonly int $bytesAfter,
    ) {}

    public static function converted(string $source, string $target, int $before, int $after): self
    {
        return new self(true, $source, $target, 'converted', $before, $after);
    }

    public static function skipped(string $source, string $reason, int $before = 0, int $after = 0): self
    {
        return new self(false, $source, null, $reason, $before, $after);
    }

    /** Сэкономленные байты. Отрицательными не бывают: без выигрыша конвертации нет. */
    public function saved(): int
    {
        return $this->converted ? max(0, $this->bytesBefore - $this->bytesAfter) : 0;
    }
}
