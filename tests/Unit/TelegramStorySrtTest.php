<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TelegramBusiness\TelegramStorySrt;
use RuntimeException;
use Tests\TestCase;

final class TelegramStorySrtTest extends TestCase
{
    private const SRT = "1\n00:00:58,500 --> 00:01:01,500\nСанскрит — это язык.\n\n2\n00:01:05,000 --> 00:01:07,000\nСледующая фраза.\n";

    public function test_cues_are_clipped_and_shifted_for_each_story_part(): void
    {
        self::assertSame("1\n00:00:58,500 --> 00:01:00,000\nСанскрит — это язык.\n",
            TelegramStorySrt::segment(self::SRT, 0, 60));
        self::assertSame("1\n00:00:00,000 --> 00:00:01,500\nСанскрит — это язык.\n\n"
            ."2\n00:00:05,000 --> 00:00:07,000\nСледующая фраза.\n",
            TelegramStorySrt::segment(self::SRT, 60, 60));
    }

    public function test_bad_srt_is_rejected_before_publication(): void
    {
        $this->expectException(RuntimeException::class);
        TelegramStorySrt::cues("1\nnot a timestamp\ntext\n");
    }
}
