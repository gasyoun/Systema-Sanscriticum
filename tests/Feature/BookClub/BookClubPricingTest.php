<?php

declare(strict_types=1);

namespace Tests\Feature\BookClub;

use App\Services\BookClub\BookClubPricing;
use Tests\TestCase;

/**
 * Собственные данные (own-data canary, H8195): тарифы доставки в коде обязаны
 * совпадать ТОЧНО с решениями кампании в book-club-219924/README.md
 * (гриллинг 12-11-2026): РФ 300 ₽ ≤2 кг; Латвия 5100 · Израиль 5800 ·
 * Германия 6200 · США 6600 · другая страна 6600 ₽.
 */
class BookClubPricingTest extends TestCase
{
    public function test_tariffs_match_campaign_ledger_exactly(): void
    {
        // РФ — 300 ₽ за посылку до 2 кг (1–2 книги).
        $this->assertSame(300.0, BookClubPricing::priceFor('RU'));
        $this->assertSame(300.0, BookClubPricing::priceFor('rf')); // регистр не важен
        $this->assertSame(300.0, BookClubPricing::priceFor(null));

        // Заграница — по стране (сверка с README.md, точное равенство).
        $this->assertSame(5100.0, BookClubPricing::priceFor('LV')); // Латвия
        $this->assertSame(5800.0, BookClubPricing::priceFor('IL')); // Израиль
        $this->assertSame(6200.0, BookClubPricing::priceFor('DE')); // Германия
        $this->assertSame(6600.0, BookClubPricing::priceFor('US')); // США

        // Другая страна и неизвестный код — 6600 ₽.
        $this->assertSame(6600.0, BookClubPricing::priceFor('OTHER'));
        $this->assertSame(6600.0, BookClubPricing::priceFor('XX'));
    }

    public function test_is_abroad_matrix(): void
    {
        $this->assertFalse(BookClubPricing::isAbroad('RU'));
        $this->assertFalse(BookClubPricing::isAbroad('rf'));
        $this->assertFalse(BookClubPricing::isAbroad(null));
        $this->assertFalse(BookClubPricing::isAbroad(''));

        $this->assertTrue(BookClubPricing::isAbroad('LV'));
        $this->assertTrue(BookClubPricing::isAbroad('OTHER'));
        $this->assertTrue(BookClubPricing::isAbroad('XX'));
    }

    public function test_country_codes_cover_config(): void
    {
        $this->assertSame(['RU', 'LV', 'IL', 'DE', 'US', 'OTHER'], BookClubPricing::countryCodes());
    }
}
