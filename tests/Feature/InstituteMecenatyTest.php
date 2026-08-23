<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstituteMecenatyTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_brand_guards(): void
    {
        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('Меценаты Института')
            ->assertSee('Добровольное пожертвование')
            ->assertDontSee('школа')
            ->assertDontSee('академия');
    }
}
