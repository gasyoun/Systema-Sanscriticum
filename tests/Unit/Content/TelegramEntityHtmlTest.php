<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\PostFormatter;
use App\Services\Content\TelegramEntityHtml;
use PHPUnit\Framework\TestCase;

class TelegramEntityHtmlTest extends TestCase
{
    public function test_entities_with_utf16_offsets_become_editor_tags(): void
    {
        // «🙏 » = 3 UTF-16 units; «Ом» начинается со смещения 3.
        $text = "🙏 Ом шанти\nкурс <тут>";
        $entities = [
            ['type' => 'bold', 'offset' => 3, 'length' => 8],
            ['type' => 'italic', 'offset' => 6, 'length' => 5],
            ['type' => 'text_link', 'offset' => 12, 'length' => 4, 'url' => 'https://samskrte.ru/?a=1&b=2'],
            ['type' => 'spoiler', 'offset' => 17, 'length' => 5],
            ['type' => 'hashtag', 'offset' => 0, 'length' => 2],
        ];

        $html = TelegramEntityHtml::toEditorHtml($text, $entities);

        $this->assertSame(
            '<p>🙏 <strong>Ом <em>шанти</em></strong><br><a href="https://samskrte.ru/?a=1&amp;b=2">курс</a> <mark>&lt;тут&gt;</mark></p>',
            $html
        );
        $this->assertSame("🙏 Ом шанти\nкурс (https://samskrte.ru/?a=1&b=2) <тут>", PostFormatter::toPlain($html));
        $this->assertSame(
            "🙏 <b>Ом <i>шанти</i></b>\n<a href=\"https://samskrte.ru/?a=1&amp;b=2\">курс</a> <tg-spoiler>&lt;тут&gt;</tg-spoiler>",
            PostFormatter::toTelegramHtml($html)
        );
    }

    public function test_plain_text_without_entities(): void
    {
        $this->assertSame('<p>раз<br>два</p>', TelegramEntityHtml::toEditorHtml("раз\nдва"));
        $this->assertSame('', TelegramEntityHtml::toEditorHtml(''));
    }
}
