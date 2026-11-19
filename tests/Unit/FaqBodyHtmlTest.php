<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FaqBodyHtml;
use PHPUnit\Framework\TestCase;

/**
 * H6301: безопасный рендер тела FAQ-раздела для /dvaram/faq. Всё
 * экранируется (основа — SupportText::safeHtml веб-чата), голые URL
 * становятся ссылками. Источник faq.md регенерируется из wiki экспортером —
 * веб-слой не доверяет ему HTML.
 */
class FaqBodyHtmlTest extends TestCase
{
    /** @test */
    public function escapes_dangerous_markup(): void
    {
        $html = FaqBodyHtml::render('<script>alert(1)</script> <img src=x onerror=alert(1)>');

        // Ни один payload не должен стать реальным тегом/атрибутом; текстом
        // показать его безопасно (это и есть «экранировано»).
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<a href', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    /** @test */
    public function linkifies_bare_urls_without_trailing_punctuation(): void
    {
        $html = FaqBodyHtml::render('Бланк: https://disk.yandex.ru/d/Nm7qsgeK3tUNUA.');

        $this->assertStringContainsString(
            '<a href="https://disk.yandex.ru/d/Nm7qsgeK3tUNUA" target="_blank"',
            $html,
        );
        // Хвостовая точка остаётся текстом после ссылки, но не внутри href.
        $this->assertStringNotContainsString('Nm7qsgeK3tUNUA."', $html);
    }

    /** @test */
    public function linkify_cannot_be_used_to_inject_attributes(): void
    {
        // URL с кавычками и угловыми скобками не должен сломать атрибут href:
        // e() уже превратил " в &quot;, < в &lt; — сырых символов в regex не остаётся.
        $html = FaqBodyHtml::render('https://x.test/a"onmouseover="alert(1)');

        $this->assertStringNotContainsString('"onmouseover=', $html);
        $this->assertStringContainsString('&quot;onmouseover=', $html);
    }

    /** @test */
    public function keeps_whitelisted_formatting_and_line_breaks(): void
    {
        $html = FaqBodyHtml::render("<b>Жирный</b>\nвторая строка");

        $this->assertStringContainsString('<b>Жирный</b>', $html);
        $this->assertStringContainsString('<br', $html);
    }
}
