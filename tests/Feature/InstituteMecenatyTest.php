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

    /** H4400 — ратифицированный состав меценатства на лендинге. */
    public function test_page_shows_patron_composition(): void
    {
        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('ежемесячный научный разбор')
            ->assertSee('ранний доступ')
            ->assertSee('благодарности меценатам в изданиях')
            ->assertSee('очных встречах');
    }

    /** H4400 — донорская юр-рамка ст. 582 ГК присутствует на странице. */
    public function test_page_carries_art_582_legal_note(): void
    {
        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('ст. 582 Гражданского кодекса РФ')
            ->assertSee('не возврату и не обмену не подлежит');
    }

    /** H4400 — три ратифицированных уровня (500/5000/250) видны на форме. */
    public function test_page_shows_three_patron_skus_when_enabled(): void
    {
        config(['institute.donations_enabled' => true]);

        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('Меценат — месяц')
            ->assertSee('500 ₽ в месяц')
            ->assertSee('Меценат — год')
            ->assertSee('5 000 ₽ в год')
            ->assertSee('Меценат — студент')
            ->assertSee('250 ₽ в месяц');
    }
}
