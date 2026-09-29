<?php

declare(strict_types=1);

namespace Tests\Unit\TelegramBusiness;

use App\Services\TelegramBusiness\TelegramStorySegments;
use PHPUnit\Framework\TestCase;

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

    public function test_oversized_video_is_bounded_to_ten_parts_and_marked_truncated(): void
    {
        $segments = TelegramStorySegments::plan(601);

        self::assertCount(10, $segments);
        self::assertSame(['offset' => 540, 'duration' => 60.0], $segments[9]);
        self::assertTrue(TelegramStorySegments::isTruncated(601));
        self::assertFalse(TelegramStorySegments::isTruncated(600));
    }
}
