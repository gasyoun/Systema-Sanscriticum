<?php

declare(strict_types=1);

namespace Tests\Feature\BookClub;

use App\Mail\BookClubAdminOrderMail;
use App\Mail\BookClubOrderShippedMail;
use App\Mail\PaypalClaimReceivedMail;
use App\Models\Book;
use App\Models\BookClubOrder;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Магазин «Книжного клуба» (H8412): витрина /knizhny-klub/kupit.
 *
 * Контракт: книга «Школьные лекции» 1000 ₽ × N + доставка (300 ₽ за посылку
 * в РФ, тариф страны за посылку за рубежом) или самовывоз 0 ₽; посылок =
 * ceil(N/2); 5+ книг принимаем и согласуем сумму письмом; остаток — books.stock;
 * флаг features.book_club_shop дефолт OFF; /dostavka не меняется.
 */
class BookClubShopTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        // Книга заводится миграцией каталога — витрина продаёт именно её.
        $this->book = Book::query()->where('slug', 'shkolnye-lekcii')->firstOrFail();
        $this->book->update(['stock' => 10]);
    }

    private function enableFlag(): void
    {
        config(['features.book_club_shop' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'buyer@example.test',
            'name' => 'Иван',
            'surname' => 'Иванов',
            'phone' => '+79001234567',
            'fulfillment' => 'purchase',
            'books_count' => 1,
            'recipient_name' => 'Иванов Иван Иванович',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, д. 1, кв. 1',
        ], $overrides);
    }

    private function abroadPayload(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'country_code' => 'LV',
            'city' => 'Рига',
            'postal_code' => 'LV-1050',
            'foreign_amount' => 55,
            'foreign_currency' => 'EUR',
            'paypal_txn' => 'TXN-777',
        ], $overrides));
    }

    private function clearRateLimit(): void
    {
        RateLimiter::clear('book-club-shop:127.0.0.1');
    }

    private function fakeTochka(): void
    {
        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/bookshop', 'paymentLinkId' => 'pl_bookshop'],
        ], 200)]);
    }

    // --- Флаг ---

    public function test_flag_off_returns_404_for_get_and_post(): void
    {
        config(['features.book_club_shop' => false]);

        $this->get(route('book-club.shop'))->assertNotFound();
        $this->post(route('book-club.shop.submit'), $this->payload())->assertNotFound();

        $this->assertDatabaseMissing('payments', ['tariff' => 'book_club_shop']);
        $this->assertDatabaseCount('book_club_orders', 0);
    }

    public function test_flag_on_shows_book_and_price_without_planeta_fields(): void
    {
        $this->enableFlag();

        $this->get(route('book-club.shop'))
            ->assertOk()
            ->assertSee('Школьные лекции')
            ->assertSee('1 000')
            ->assertDontSee('planeta_email')
            ->assertDontSee('Номер заказа planeta');
    }

    public function test_out_of_stock_page_says_backorder_and_still_sells(): void
    {
        $this->enableFlag();
        $this->book->update(['stock' => 0]);
        $this->fakeTochka();

        $this->get(route('book-club.shop'))
            ->assertOk()
            ->assertSee('Под заказ 2–3 недели');

        $this->post(route('book-club.shop.submit'), $this->payload())
            ->assertRedirect('https://pay.tochka/bookshop');

        $this->assertDatabaseHas('book_club_orders', ['books_count' => 1]);
    }

    // --- Цены РФ: книга 1000 × N + 300 за посылку ---

    public function test_rf_single_book_pays_book_plus_one_parcel(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $this->post(route('book-club.shop.submit'), $this->payload())
            ->assertRedirect('https://pay.tochka/bookshop');

        $this->assertDatabaseHas('payments', [
            'tariff' => 'book_club_shop',
            'status' => 'pending',
            'amount' => 1300,
            'course_id' => null,
            'transaction_id' => 'pl_bookshop',
        ]);

        $this->assertDatabaseHas('book_club_orders', [
            'mode' => BookClubOrder::MODE_PURCHASE,
            'books_count' => 1,
            'amount' => 1300,
            'delivery_amount' => 300,
            'status' => BookClubOrder::STATUS_PENDING,
            'country_code' => 'RU',
            'recipient_name' => 'Иванов Иван Иванович',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'buyer@example.test']);
    }

    public function test_rf_two_books_still_one_parcel(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $this->post(route('book-club.shop.submit'), $this->payload(['books_count' => 2]));

        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_shop', 'amount' => 2300]);
        $this->assertDatabaseHas('book_club_orders', ['books_count' => 2, 'amount' => 2300, 'delivery_amount' => 300]);
    }

    public function test_rf_three_books_pay_two_parcels(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $this->post(route('book-club.shop.submit'), $this->payload(['books_count' => 3]));

        // 3 000 ₽ книги + 2 посылки × 300 ₽.
        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_shop', 'amount' => 3600]);
        $this->assertDatabaseHas('book_club_orders', ['books_count' => 3, 'amount' => 3600, 'delivery_amount' => 600]);
    }

    // --- Заграница: PayPal-заявка, тариф страны за посылку ---

    public function test_abroad_single_book_pays_book_plus_country_tariff(): void
    {
        $this->enableFlag();
        config(['services.admin.email' => 'admin@example.test']);
        Http::fake(); // Tochka вызываться НЕ должна

        $this->post(route('book-club.shop.submit'), $this->abroadPayload())
            ->assertRedirect(route('book-club.shop'))
            ->assertSessionHas('success');

        // 1 000 ₽ книга + 5 100 ₽ посылка в Латвию.
        $this->assertDatabaseHas('payments', [
            'tariff' => 'book_club_shop',
            'amount' => 6100,
            'provider' => Payment::PROVIDER_PAYPAL,
            'foreign_amount' => 55,
            'foreign_currency' => 'EUR',
        ]);
        $this->assertDatabaseHas('book_club_orders', ['mode' => BookClubOrder::MODE_PURCHASE, 'country_code' => 'LV', 'amount' => 6100]);

        Mail::assertQueued(PaypalClaimReceivedMail::class);
        Http::assertNothingSent();
    }

    public function test_abroad_two_books_pay_two_parcels(): void
    {
        $this->enableFlag();
        Http::fake();

        $this->post(route('book-club.shop.submit'), $this->abroadPayload(['books_count' => 2]));

        // 2 книги = ОДНА посылка: 2 000 ₽ книги + 5 100 ₽ (Латвия).
        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_shop', 'amount' => 7100]);
        $this->assertDatabaseHas('book_club_orders', ['books_count' => 2, 'amount' => 7100, 'delivery_amount' => 5100]);
    }

    // --- Самовывоз и 5+ ---

    public function test_pickup_needs_no_address_and_has_zero_delivery(): void
    {
        $this->enableFlag();
        config(['services.admin.email' => 'admin@example.test']);
        Http::fake();

        $this->post(route('book-club.shop.submit'), $this->payload([
            'fulfillment' => 'pickup',
            'books_count' => 2,
            'recipient_name' => '',
            'country_code' => '',
            'city' => '',
            'postal_code' => '',
            'address_line' => '',
        ]))
            ->assertRedirect(route('book-club.shop'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('book_club_orders', [
            'mode' => BookClubOrder::MODE_PICKUP,
            'books_count' => 2,
            'amount' => 2000,
            'delivery_amount' => 0,
            'status' => BookClubOrder::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_shop', 'amount' => 2000]);

        Mail::assertQueued(BookClubAdminOrderMail::class);
        Http::assertNothingSent();
    }

    public function test_five_books_are_accepted_with_manual_quote(): void
    {
        $this->enableFlag();
        config(['services.admin.email' => 'admin@example.test']);
        Http::fake();

        $this->post(route('book-club.shop.submit'), $this->payload(['books_count' => 5]))
            ->assertRedirect(route('book-club.shop'))
            ->assertSessionHas('success');

        // 5 000 ₽ книги + 3 посылки × 300 ₽ — сумма зафиксирована, но онлайн
        // платёж не запускается: сумму согласуем письмом (Q2/Q13).
        $this->assertDatabaseHas('book_club_orders', [
            'books_count' => 5,
            'amount' => 5900,
            'delivery_amount' => 900,
            'status' => BookClubOrder::STATUS_PENDING,
        ]);

        Mail::assertQueued(BookClubAdminOrderMail::class);
        Http::assertNothingSent();
    }

    public function test_abroad_five_books_skip_paypal_prepayment_and_details(): void
    {
        $this->enableFlag();
        config(['services.admin.email' => 'admin@example.test']);
        Http::fake();

        // 5+ книг за рубежом: сумму ещё не согласовали, поэтому PayPal-поля
        // (сумма/валюта/номер транзакции) НЕ требуются — заказ создаётся, письмо
        // администратору, предоплату не просим.
        $this->post(route('book-club.shop.submit'), $this->payload([
            'books_count' => 5,
            'country_code' => 'DE',
            'city' => 'Берлин',
            'postal_code' => '10115',
        ]))
            ->assertRedirect(route('book-club.shop'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        // 5 000 ₽ книги + 3 посылки × 6 200 ₽ (Германия).
        $this->assertDatabaseHas('book_club_orders', [
            'books_count' => 5,
            'country_code' => 'DE',
            'amount' => 23600,
            'delivery_amount' => 18600,
        ]);

        $this->assertDatabaseHas('payments', [
            'tariff' => 'book_club_shop',
            'provider' => null,
            'foreign_amount' => null,
        ]);

        Mail::assertQueued(BookClubAdminOrderMail::class);
        Mail::assertNotQueued(PaypalClaimReceivedMail::class);
        Http::assertNothingSent();
    }

    public function test_stock_is_reserved_on_order_and_restored_on_tochka_failure(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $this->post(route('book-club.shop.submit'), $this->payload(['books_count' => 2]));
        $this->assertSame(8, $this->book->fresh()->stock);

        $this->clearRateLimit();
        Http::fake(['*' => fn () => throw new ConnectionException('down')]);

        $this->post(route('book-club.shop.submit'), $this->payload([
            'email' => 'second@example.test',
            'books_count' => 3,
        ]))->assertSessionHas('error');

        // Резерв второго заказа возвращён: после первого заказа остаток 8,
        // второй резерв (−3) снят неудачной оплатой (+3) — снова 8.
        $this->assertSame(8, $this->book->fresh()->stock);
        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_shop', 'status' => 'failed']);
    }

    public function test_http_error_from_tochka_also_returns_stock(): void
    {
        $this->enableFlag();
        Http::fake(['*' => Http::response(['error' => 'gate'], 500)]);

        $this->post(route('book-club.shop.submit'), $this->payload(['books_count' => 3]))
            ->assertSessionHas('error');

        // Резерв снят и при HTTP-ошибке провайдера, не только при обрыве связи.
        $this->assertSame(10, $this->book->fresh()->stock);
        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_shop', 'status' => 'failed']);
        $this->assertDatabaseHas('book_club_orders', ['books_count' => 3, 'status' => BookClubOrder::STATUS_PENDING]);
    }

    public function test_200_without_payment_link_is_treated_as_failure(): void
    {
        $this->enableFlag();
        Http::fake(['*' => Http::response(['Data' => ['paymentLinkId' => 'pl_no_link']], 200)]);

        $this->post(route('book-club.shop.submit'), $this->payload(['books_count' => 2]))
            ->assertSessionHas('error');

        $this->assertSame(10, $this->book->fresh()->stock);
        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_shop', 'status' => 'failed', 'transaction_id' => null]);
    }

    public function test_backorder_goes_negative_so_queue_is_visible(): void
    {
        $this->enableFlag();
        $this->book->update(['stock' => 0]);
        $this->fakeTochka();

        $this->post(route('book-club.shop.submit'), $this->payload());

        $this->assertSame(-1, $this->book->fresh()->stock);
    }

    // --- Валидация и анти-takeover ---

    public function test_delivery_requires_address_and_country_name_for_other(): void
    {
        $this->enableFlag();

        $this->from(route('book-club.shop'))
            ->post(route('book-club.shop.submit'), $this->payload([
                'country_code' => 'OTHER',
                'country_name' => '',
                'city' => '',
                'address_line' => '',
            ]))
            ->assertSessionHasErrors(['country_name', 'city', 'address_line']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guest_with_existing_email_is_rejected(): void
    {
        $this->enableFlag();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->from(route('book-club.shop'))
            ->post(route('book-club.shop.submit'), $this->payload(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_double_click_creates_one_order(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $user = User::factory()->create(['email' => 'loyal@example.test']);
        $this->actingAs($user);

        $this->post(route('book-club.shop.submit'), $this->payload(['email' => 'loyal@example.test']))
            ->assertRedirect('https://pay.tochka/bookshop');

        $this->clearRateLimit();

        $this->post(route('book-club.shop.submit'), $this->payload(['email' => 'loyal@example.test']))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('book_club_orders', 1);
    }

    // --- Статусы и письмо об отправке ---

    public function test_shipped_transition_stores_tracking_and_queues_letter(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $this->post(route('book-club.shop.submit'), $this->payload());

        $order = BookClubOrder::query()->firstOrFail();
        $order->update([
            'status' => BookClubOrder::STATUS_SHIPPED,
            'tracking_number' => 'RU123456789',
        ]);

        $order->refresh();

        $this->assertSame(BookClubOrder::STATUS_SHIPPED, $order->status);
        $this->assertNotNull($order->shipped_at);
        $this->assertSame('RU123456789', $order->tracking_number);
        Mail::assertQueued(BookClubOrderShippedMail::class);

        // Повторные апдейты (не shipped) письма не шлют.
        $order->update(['status' => BookClubOrder::STATUS_PAID]);
        Mail::assertQueued(BookClubOrderShippedMail::class, 1);
    }

    // --- Регресс: доставка участникам кампании не изменилась ---

    public function test_delivery_checkout_behaviour_is_untouched_by_shop_flag(): void
    {
        $this->enableFlag();
        config(['features.book_club_delivery' => true]);

        // Кросс-ссылка на витрину появилась, сам чекаут доставки работает.
        $this->get(route('book-club.show'))
            ->assertOk()
            ->assertSee('Книжный клуб')
            ->assertSee(route('book-club.shop'));

        $this->fakeTochka();

        $this->post(route('book-club.submit'), [
            'email' => 'backer@example.test',
            'name' => 'Иван',
            'surname' => 'Иванов',
            'recipient_name' => 'Иванов Иван Иванович',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, д. 1',
            'phone' => '+79001234567',
            'planeta_email' => 'backer@mozmail.com',
            'planeta_order_number' => '20241121-4433387',
        ])->assertRedirect('https://pay.tochka/bookshop');

        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_delivery', 'amount' => 300]);
        $this->assertDatabaseHas('book_club_orders', [
            'mode' => BookClubOrder::MODE_DELIVERY_ONLY,
            'amount' => 300,
            'delivery_amount' => 300,
        ]);
    }

    public function test_shop_flag_off_leaves_delivery_page_working(): void
    {
        config(['features.book_club_shop' => false, 'features.book_club_delivery' => true]);

        $this->get(route('book-club.show'))
            ->assertOk()
            ->assertDontSee(route('book-club.shop'));
    }
}
