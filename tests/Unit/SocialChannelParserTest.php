<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Messaging\SocialChannelParser;
use Tests\TestCase;

class SocialChannelParserTest extends TestCase
{
    private SocialChannelParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SocialChannelParser;
    }

    /** @test */
    public function empty_input_returns_null_channel(): void
    {
        $this->assertSame(['channel' => null, 'handle' => null], $this->parser->parse(null));
        $this->assertSame(['channel' => null, 'handle' => null], $this->parser->parse(''));
        $this->assertSame(['channel' => null, 'handle' => null], $this->parser->parse('   '));
    }

    /** @test */
    public function telegram_at_handle(): void
    {
        $this->assertSame(
            ['channel' => 'telegram', 'handle' => 'durov'],
            $this->parser->parse('@durov')
        );
    }

    /** @test */
    public function telegram_tme_url(): void
    {
        $this->assertSame(
            ['channel' => 'telegram', 'handle' => 'durov'],
            $this->parser->parse('https://t.me/durov')
        );

        $this->assertSame(
            ['channel' => 'telegram', 'handle' => 'durov'],
            $this->parser->parse('t.me/durov')
        );
    }

    /** @test */
    public function telegram_resolve_scheme(): void
    {
        $this->assertSame(
            ['channel' => 'telegram', 'handle' => 'durov'],
            $this->parser->parse('tg://resolve?domain=durov')
        );
    }

    /** @test */
    public function telegram_joinchat_is_not_a_handle(): void
    {
        // t.me/joinchat/X — это invite-ссылка, не personal handle
        $result = $this->parser->parse('https://t.me/joinchat/AAAAAEXAMPLE');
        $this->assertNull($result['channel']);
    }

    /** @test */
    public function vk_full_url(): void
    {
        $this->assertSame(
            ['channel' => 'vk', 'handle' => 'durov'],
            $this->parser->parse('https://vk.com/durov')
        );

        $this->assertSame(
            ['channel' => 'vk', 'handle' => 'id1'],
            $this->parser->parse('vk.com/id1')
        );
    }

    /** @test */
    public function vk_ru_domain_and_mobile_subdomain(): void
    {
        // Реальный кейс Lead Елены: vk.ru-ссылка раньше не распознавалась
        // и канал ошибочно падал в telegram-fallback.
        $this->assertSame(
            ['channel' => 'vk', 'handle' => 'id453633563'],
            $this->parser->parse('https://vk.ru/id453633563')
        );

        $this->assertSame(
            ['channel' => 'vk', 'handle' => 'durov'],
            $this->parser->parse('https://m.vk.com/durov')
        );
    }

    /** @test */
    public function vk_shortform(): void
    {
        $this->assertSame(
            ['channel' => 'vk', 'handle' => 'durov'],
            $this->parser->parse('vk: durov')
        );
    }

    /** @test */
    public function max_url(): void
    {
        $this->assertSame(
            ['channel' => 'max', 'handle' => 'someone'],
            $this->parser->parse('https://max.ru/@someone')
        );

        $this->assertSame(
            ['channel' => 'max', 'handle' => 'someone'],
            $this->parser->parse('max.ru/someone')
        );
    }

    /** @test */
    public function instagram_recognized_but_returns_null(): void
    {
        // Instagram распознаём, но доставить не можем — fallback на дефолт лендинга.
        $result = $this->parser->parse('https://instagram.com/durov');
        $this->assertNull($result['channel']);
    }

    /** @test */
    public function unknown_input_returns_null(): void
    {
        $this->assertNull($this->parser->parse('нет соцсетей')['channel']);
        $this->assertNull($this->parser->parse('абракадабра')['channel']);
        $this->assertNull($this->parser->parse('user@example.com')['channel']);
    }

    /** @test */
    public function too_short_handle_rejected(): void
    {
        // \w{3,} — handle меньше 3 символов не должен матчиться
        $this->assertNull($this->parser->parse('@a')['channel']);
        $this->assertNull($this->parser->parse('@ab')['channel']);
    }
}
