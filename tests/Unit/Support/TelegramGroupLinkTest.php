<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TelegramGroupLink;
use PHPUnit\Framework\TestCase;

/**
 * H3557: t.me-ссылки на группы для TG-оповещений.
 */
class TelegramGroupLinkTest extends TestCase
{
    public function test_supergroup_chat_id_builds_private_link(): void
    {
        $this->assertSame('https://t.me/c/999', TelegramGroupLink::fromChatId('-100999'));
        $this->assertSame(
            'https://t.me/c/2079934542',
            TelegramGroupLink::fromChatId('-1002079934542'),
        );
    }

    public function test_non_supergroup_ids_have_no_link(): void
    {
        $this->assertNull(TelegramGroupLink::fromChatId('11111'));
        $this->assertNull(TelegramGroupLink::fromChatId('7961639774'));
        $this->assertNull(TelegramGroupLink::fromChatId(''));
        $this->assertNull(TelegramGroupLink::fromChatId(null));
    }

    public function test_anchor_wraps_label_when_linked_and_falls_back_to_text(): void
    {
        $this->assertSame(
            '<a href="https://t.me/c/999">Группа А</a>',
            TelegramGroupLink::anchor('-100999', 'Группа А'),
        );
        // Без ссылки — экранированный текст, формат TG-сообщения не ломаем.
        $this->assertSame('Личка', TelegramGroupLink::anchor('11111', 'Личка'));
        $this->assertSame('группа', TelegramGroupLink::anchor(null, ''));
        $this->assertSame('A &amp; B', TelegramGroupLink::anchor(null, 'A & B'));
    }
}
