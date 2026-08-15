<?php

declare(strict_types=1);

namespace App\Services\Design;

use App\Models\CourseDesignAsset;

/**
 * Результат переноса одной обложки.
 *
 * Значение, а не исключение: массовый прогон обязан дойти до конца по всем
 * выделенным курсам и отчитаться агрегированно, а исключение на первом же
 * курсе без обложки оборвало бы цикл.
 */
final readonly class CoverImportResult
{
    public function __construct(
        public CoverImportOutcome $outcome,
        public ?CourseDesignAsset $asset = null,
        public ?string $detail = null,
    ) {}

    public static function imported(CourseDesignAsset $asset): self
    {
        return new self(CoverImportOutcome::Imported, $asset);
    }

    public static function of(CoverImportOutcome $outcome, ?string $detail = null): self
    {
        return new self($outcome, null, $detail);
    }

    public function isImported(): bool
    {
        return $this->outcome === CoverImportOutcome::Imported;
    }
}
