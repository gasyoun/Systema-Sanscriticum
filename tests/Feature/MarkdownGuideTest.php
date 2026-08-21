<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\MarkdownGuide;
use Tests\TestCase;

class MarkdownGuideTest extends TestCase
{
    public function test_renders_fixture_markdown_and_rewrites_screenshot_src(): void
    {
        $html = MarkdownGuide::html('tests/fixtures/guides/sample.md');

        $this->assertIsString($html);
        $this->assertStringContainsString('Образец', $html);
        $this->assertStringContainsString(MarkdownGuide::SCREENSHOT_BASE.'student-guide/sample-1440.png', $html);
        $this->assertStringNotContainsString('src="screenshots/', $html);
    }

    public function test_missing_file_returns_null(): void
    {
        $this->assertNull(MarkdownGuide::html('docs/THIS_GUIDE_DOES_NOT_EXIST.md'));
    }

    public function test_custom_screenshot_base_rewrites_accountant_prefix(): void
    {
        $html = MarkdownGuide::html(
            'tests/fixtures/guides/sample.md',
            '/staff/accountant-guide-shots/',
            'screenshots/student-guide/',
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('/staff/accountant-guide-shots/sample-1440.png', $html);
        $this->assertStringNotContainsString('src="screenshots/', $html);
        $this->assertStringNotContainsString(MarkdownGuide::SCREENSHOT_BASE, $html);
    }
}
