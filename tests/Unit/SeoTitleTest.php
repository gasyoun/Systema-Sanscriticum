<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SeoTitle;
use PHPUnit\Framework\TestCase;

/**
 * H-title: 60-character title budget, brand fallback and suffix stripping.
 */
class SeoTitleTest extends TestCase
{
    public function test_short_base_gets_full_brand(): void
    {
        $this->assertSame(
            'Курсы санскрита с нуля | Общество ревнителей санскрита',
            SeoTitle::render('Курсы санскрита с нуля')
        );
    }

    public function test_medium_base_falls_back_to_short_brand(): void
    {
        $title = SeoTitle::render('Грамматика санскрита онлайн с М. Ю. Гасунсом');

        $this->assertStringEndsWith('| ОРС', $title);
        $this->assertLessThanOrEqual(SeoTitle::MAX_LENGTH, mb_strlen($title));
    }

    public function test_long_base_is_truncated_within_budget(): void
    {
        $title = SeoTitle::render(str_repeat('очень длинный заголовок ', 5));

        $this->assertLessThanOrEqual(SeoTitle::MAX_LENGTH, mb_strlen($title));
        $this->assertStringContainsString('…', $title);
        $this->assertStringEndsWith('| ОРС', $title);
    }

    public function test_existing_brand_is_stripped_before_reappending(): void
    {
        $this->assertSame(
            'Статьи о санскрите | Общество ревнителей санскрита',
            SeoTitle::render('Статьи о санскрите — Общество ревнителей санскрита — ОРС')
        );
    }

    public function test_plain_does_not_add_brand(): void
    {
        $title = SeoTitle::plain('Бесплатное вводное занятие: Традиции толкования упанишад');

        $this->assertStringNotContainsString('| ОРС', $title);
        $this->assertLessThanOrEqual(SeoTitle::MAX_LENGTH, mb_strlen($title));
    }

    public function test_empty_base_returns_org_name(): void
    {
        $this->assertSame('Общество ревнителей санскрита', SeoTitle::render(null));
    }
}
