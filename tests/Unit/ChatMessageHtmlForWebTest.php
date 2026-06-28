<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ChatMessage;
use PHPUnit\Framework\TestCase;

/**
 * Безопасный рендер Telegram-форматирования сообщений в web-чате (helpdesk):
 * простые теги становятся HTML, всё остальное экранируется.
 */
class ChatMessageHtmlForWebTest extends TestCase
{
    /** @test */
    public function renders_simple_telegram_formatting_as_html(): void
    {
        $html = (new ChatMessage(['text' => '📚 <b>Ваши группы</b>']))->htmlForWeb();

        $this->assertStringContainsString('<b>Ваши группы</b>', $html);
    }

    /** @test */
    public function escapes_dangerous_markup_and_attributes(): void
    {
        $html = (new ChatMessage(['text' => '<script>alert(1)</script> <a href="javascript:x">x</a>']))->htmlForWeb();

        // Скрипт и ссылка с атрибутами не должны стать исполняемым HTML.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<a href', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @test */
    public function converts_newlines_to_br(): void
    {
        $html = (new ChatMessage(['text' => "строка1\nстрока2"]))->htmlForWeb();

        $this->assertStringContainsString('<br', $html);
    }
}
