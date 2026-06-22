<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Bot\TelegramFormatter;
use PHPUnit\Framework\TestCase;

class TelegramFormatterTest extends TestCase
{
    public function test_converts_markdown_bold_to_html(): void
    {
        $this->assertSame('<b>Цена</b>: 8 000 ₽', TelegramFormatter::toHtml('**Цена**: 8 000 ₽'));
        $this->assertSame('<b>жирно</b>', TelegramFormatter::toHtml('__жирно__'));
    }

    public function test_converts_markdown_heading_without_nested_bold(): void
    {
        $this->assertSame(
            '<b>1. Грамматика по Кочергиной</b>',
            TelegramFormatter::toHtml('### 1. **Грамматика по Кочергиной**')
        );
    }

    public function test_converts_list_markers_to_bullets(): void
    {
        $this->assertSame("• Формат\n• Цена", TelegramFormatter::toHtml("- Формат\n* Цена"));
    }

    public function test_converts_italic_links_and_inline_code(): void
    {
        $this->assertSame('<i>курсив</i>', TelegramFormatter::toHtml('*курсив*'));
        $this->assertSame('<code>block_1</code>', TelegramFormatter::toHtml('`block_1`'));
        $this->assertSame(
            '<a href="https://samskrte.ru/x">ссылка</a>',
            TelegramFormatter::toHtml('[ссылка](https://samskrte.ru/x)')
        );
    }

    public function test_preserves_existing_valid_html_tags(): void
    {
        $this->assertSame('Уже <b>валидный</b> текст', TelegramFormatter::toHtml('Уже <b>валидный</b> текст'));
    }

    public function test_escapes_bare_special_chars(): void
    {
        $this->assertSame('Цена &lt; 8000 &amp; скидка &gt; 0', TelegramFormatter::toHtml('Цена < 8000 & скидка > 0'));
    }

    public function test_to_plain_strips_all_markup_for_vk(): void
    {
        $in = "### Курс\n- **Цена**: 8 000 ₽\n[ссылка](https://x.ru) и `код`";
        $this->assertSame("Курс\n• Цена: 8 000 ₽\nссылка и код", TelegramFormatter::toPlain($in));
    }
}
