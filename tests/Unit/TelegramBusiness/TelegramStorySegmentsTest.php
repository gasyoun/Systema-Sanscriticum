<?php

declare(strict_types=1);

namespace Tests\Unit\TelegramBusiness;

use App\Services\TelegramBusiness\TelegramStorySegments;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelegramStorySegmentsTest extends TestCase
{
    public function test_152_second_video_is_three_consecutive_parts(): void
    {
        self::assertSame([
            ['offset' => 0, 'duration' => 60.0],
            ['offset' => 60, 'duration' => 60.0],
            ['offset' => 120, 'duration' => 32.37],
        ], TelegramStorySegments::plan(152.37));
    }

    public function test_exact_boundary_is_not_given_an_empty_part(): void
    {
        self::assertCount(1, TelegramStorySegments::plan(60));
        self::assertCount(2, TelegramStorySegments::plan(120));
        self::assertCount(2, TelegramStorySegments::plan(120.0001));
    }

    public function test_oversized_video_is_rejected_without_partial_publication(): void
    {
        $this->expectException(RuntimeException::class);
        TelegramStorySegments::plan(601);
    }
}
