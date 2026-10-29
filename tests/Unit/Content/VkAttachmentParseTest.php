<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Models\ContentCalendarSlot;
use PHPUnit\Framework\TestCase;

class VkAttachmentParseTest extends TestCase
{
    public function test_links_of_every_shape_become_attachment_ids(): void
    {
        $raw = "https://vk.com/photo-236973776_457239017\n"
            .'https://vk.com/samskrte?z=photo-236973776_457239018%2Fwall-236973776_52 '
            .'https://vkvideo.ru/video-236973776_456239020, https://vk.com/clip-236973776_456239021 '
            .'video14488224_456239099_ab12cd34ef https://vk.com/photo-236973776_457239017';

        $this->assertSame([
            'photo-236973776_457239017',
            'photo-236973776_457239018',
            'video-236973776_456239020',
            'video-236973776_456239021',
            'video14488224_456239099_ab12cd34ef',
        ], ContentCalendarSlot::parseVkAttachments($raw));
    }

    public function test_non_media_links_are_ignored(): void
    {
        $this->assertSame([], ContentCalendarSlot::parseVkAttachments('https://vk.com/wall-236973776_52 https://vk.com/album-1_2'));
        $this->assertSame([], ContentCalendarSlot::parseVkAttachments(null));
    }
}
