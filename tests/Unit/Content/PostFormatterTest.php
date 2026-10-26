<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\PostFormatter;
use PHPUnit\Framework\TestCase;

class PostFormatterTest extends TestCase
{
    public function test_editor_marks_become_telegram_tags(): void
    {
        $html = '<p><strong>Ж</strong> <em>К</em> <u>П</u> <s>З</s> <mark>спойлер</mark> <code>x=1</code></p>';

        $this->assertSame(
            '<b>Ж</b> <i>К</i> <u>П</u> <s>З</s> <tg-spoiler>спойлер</tg-spoiler> <code>x=1</code>',
            PostFormatter::toTelegramHtml($html)
        );
    }

    public function test_paragraphs_breaks_quote_and_link(): void
    {
        $html = '<p>Первая строка<br>вторая</p><p></p><blockquote><p>Цитата</p></blockquote>'
            .'<p>Курс <a href="https://samskrte.ru/c?a=1&b=2" target="_blank" rel="noopener">здесь</a></p>';

        $this->assertSame(
            "Первая строка\nвторая\n\n<blockquote>Цитата</blockquote>\n"
            .'Курс <a href="https://samskrte.ru/c?a=1&amp;b=2">здесь</a>',
            PostFormatter::toTelegramHtml($html)
        );
        $this->assertSame(
            "Первая строка\nвторая\n\nЦитата\nКурс здесь (https://samskrte.ru/c?a=1&b=2)",
            PostFormatter::toPlain($html)
        );
    }

    public function test_text_special_chars_are_escaped_for_telegram(): void
    {
        $this->assertSame('a &lt; b &amp; c', PostFormatter::toTelegramHtml('<p>a &lt; b &amp; c</p>'));
        $this->assertSame('a < b & c', PostFormatter::toPlain('<p>a &lt; b &amp; c</p>'));
    }

    public function test_unsafe_links_and_unknown_tags_are_dropped(): void
    {
        $html = '<h2>Заголовок</h2><p><a href="javascript:alert(1)">тык</a><span style="color:red">цвет</span><script>x()</script></p>';

        $this->assertSame("<b>Заголовок</b>\nтыкцвет", PostFormatter::toTelegramHtml($html));
    }

    public function test_lists_and_code_block(): void
    {
        $html = '<ul><li><p>раз</p></li><li><p>два</p></li></ul><ol><li><p>а</p></li><li><p>б</p></li></ol><pre><code>if a &lt; b:</code></pre>';

        $this->assertSame("• раз\n• два\n1. а\n2. б\n<pre>if a &lt; b:</pre>", PostFormatter::toTelegramHtml($html));
    }

    public function test_legacy_plain_text_slot(): void
    {
        $plain = "«Наши учителя»: Гасунс.\nСтраница: https://samskrte.ru/t <3";

        $this->assertSame("«Наши учителя»: Гасунс.\nСтраница: https://samskrte.ru/t &lt;3", PostFormatter::toTelegramHtml($plain));
        $this->assertSame($plain, PostFormatter::toPlain($plain));
    }

    public function test_legacy_plain_text_becomes_editor_paragraphs(): void
    {
        $this->assertSame('<p>раз &lt;3</p><p></p><p>два</p>', PostFormatter::toEditorHtml("раз <3\n\nдва"));
        $this->assertSame('<p>уже HTML</p>', PostFormatter::toEditorHtml('<p>уже HTML</p>'));
        $this->assertSame("раз &lt;3\n\nдва", PostFormatter::toTelegramHtml(PostFormatter::toEditorHtml("раз <3\n\nдва")));
    }

    public function test_telegram_length_counts_visible_utf16(): void
    {
        // эмодзи 🙏 = 2 UTF-16 units; URL ссылки в длину не входит.
        $this->assertSame(7, PostFormatter::telegramLength('<p><strong>ом</strong> <a href="https://samskrte.ru">ok</a>🙏</p>'));
    }
}
