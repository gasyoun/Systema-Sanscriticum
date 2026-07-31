<?php

namespace Tests\Feature;

use Tests\TestCase;

class UptimePageTest extends TestCase
{
    public function test_public_uptime_page_is_reachable(): void
    {
        $this->get('/uptime')
            ->assertOk()
            ->assertSee('Сайт не открывается', false)
            ->assertSee('@rusamskrtam', false)
            ->assertSee('https://t.me/rusamskrtam', false)
            ->assertSee('Не пишите Артёму', false);
    }
}
