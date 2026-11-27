<?php

declare(strict_types=1);

namespace Tests\Feature\BookClub;

use App\Models\Book;
use App\Services\BookClub\BookClubShopPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Арифметика витрины магазина (H8412): посылки, тарифы, порог ручного согласования.
 */
class BookClubShopPricingTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::query()->where('slug', 'shkolnye-lekcii')->firstOrFail();
    }

    public function test_parcel_count_is_ceil_books_per_parcel(): void
    {
        $this->assertSame(1, BookClubShopPricing::parcelCount(1));
        $this->assertSame(1, BookClubShopPricing::parcelCount(2));
        $this->assertSame(2, BookClubShopPricing::parcelCount(3));
        $this->assertSame(2, BookClubShopPricing::parcelCount(4));
        $this->assertSame(3, BookClubShopPricing::parcelCount(5));
        $this->assertSame(4, BookClubShopPricing::parcelCount(7));
    }

    public function test_rf_delivery_is_300_per_parcel(): void
    {
        $this->assertSame(300.0, BookClubShopPricing::deliveryFor('RU', 1));
        $this->assertSame(300.0, BookClubShopPricing::deliveryFor('RU', 2));
        $this->assertSame(600.0, BookClubShopPricing::deliveryFor('RU', 3));
        $this->assertSame(900.0, BookClubShopPricing::deliveryFor('RU', 5));
    }

    public function test_abroad_delivery_uses_country_tariff_per_parcel(): void
    {
        $this->assertSame(5100.0, BookClubShopPricing::deliveryFor('LV', 1));
        $this->assertSame(5800.0, BookClubShopPricing::deliveryFor('IL', 2));
        $this->assertSame(12400.0, BookClubShopPricing::deliveryFor('DE', 3));
        $this->assertSame(13200.0, BookClubShopPricing::deliveryFor('US', 4));
        $this->assertSame(6600.0, BookClubShopPricing::deliveryFor('OTHER', 1));
    }

    public function test_pickup_delivery_is_free_regardless_of_country(): void
    {
        $this->assertSame(0.0, BookClubShopPricing::deliveryFor('RU', 3, pickup: true));
        $this->assertSame(0.0, BookClubShopPricing::deliveryFor('US', 5, pickup: true));
    }

    public function test_quote_is_book_price_times_n_plus_delivery(): void
    {
        $quote = BookClubShopPricing::quote($this->book(), 3, 'RU');

        $this->assertSame(3, $quote['books']);
        $this->assertSame(1000.0, $quote['unit_price']);
        $this->assertSame(3000.0, $quote['books_total']);
        $this->assertSame(2, $quote['parcels']);
        $this->assertSame(600.0, $quote['delivery']);
        $this->assertSame(3600.0, $quote['total']);
        $this->assertFalse($quote['manual']);
    }

    public function test_five_or_more_books_require_manual_quote(): void
    {
        $this->assertFalse(BookClubShopPricing::requiresManualQuote(4));
        $this->assertTrue(BookClubShopPricing::requiresManualQuote(5));
        $this->assertTrue(BookClubShopPricing::requiresManualQuote(9));
        $this->assertTrue(BookClubShopPricing::quote($this->book(), 5, 'RU')['manual']);
    }

    public function test_pickup_quote_has_zero_delivery(): void
    {
        $quote = BookClubShopPricing::quote($this->book(), 2, null, pickup: true);

        $this->assertSame(2000.0, $quote['books_total']);
        $this->assertSame(0.0, $quote['delivery']);
        $this->assertSame(2000.0, $quote['total']);
    }
}
