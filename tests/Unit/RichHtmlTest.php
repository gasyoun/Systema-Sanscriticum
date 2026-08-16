<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RichHtml;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * H2896 residual #8 — teacher Tiptap HTML must lose script / handlers / javascript:.
 */
class RichHtmlTest extends TestCase
{
    #[Test]
    public function strips_script_and_event_handlers(): void
    {
        $html = RichHtml::sanitize(
            '<p>Курс</p><script>alert(1)</script><img src="x" onerror="alert(1)">'
        );

        $this->assertStringContainsString('Курс', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    #[Test]
    public function drops_javascript_href(): void
    {
        $html = RichHtml::sanitize('<p><a href="javascript:alert(1)">тык</a></p>');

        $this->assertStringContainsString('тык', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function keeps_https_link_and_relative_image(): void
    {
        $html = RichHtml::sanitize(
            '<p><a href="https://t.me/example">чат</a></p><img src="/storage/images/slide.jpg" alt="слайд">'
        );

        $this->assertStringContainsString('https://t.me/example', $html);
        $this->assertStringContainsString('/storage/images/slide.jpg', $html);
        $this->assertStringContainsString('чат', $html);
    }

    #[Test]
    public function empty_and_null_are_empty_string(): void
    {
        $this->assertSame('', RichHtml::sanitize(null));
        $this->assertSame('', RichHtml::sanitize('   '));
    }
}
