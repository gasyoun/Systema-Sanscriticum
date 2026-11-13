<?php

declare(strict_types=1);

namespace Tests\Feature\BookClub;

use App\Mail\PaypalClaimReceivedMail;
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
 * Чекаут «Книжный клуб» — доставка кампании planeta 219924 (H8195).
 *
 * Контракт: фича-флаг BOOK_CLUB_DELIVERY дефолт OFF (маршруты 404), цена —
 * строго из config/book_club.php, платёж НЕ выдаёт доступ к курсам
 * (course_id=null, tariff='book_club_delivery' — не ключ доступа).
 */
class BookClubCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function enableFlag(): void
    {
        config(['features.book_club_delivery' => true]);
    }

    /** Валидный РФ-пейлоад (доставка в РФ, 300 ₽). */
    private function rfPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'buyer@example.test',
            'name' => 'Иван',
            'surname' => 'Иванов',
            'recipient_name' => 'Иванов Иван Иванович',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, д. 1, кв. 1',
            'phone' => '+79001234567',
            'planeta_email' => 'backer@mozmail.com',
            'planeta_order_number' => '20241121-4433387',
        ], $overrides);
    }

    /** Валидный заграничный пейлоад (PayPal-заявка). */
    private function abroadPayload(array $overrides = []): array
    {
        return array_merge($this->rfPayload([
            'country_code' => 'LV',
            'city' => 'Рига',
            'postal_code' => 'LV-1050',
            'foreign_amount' => 55,
            'foreign_currency' => 'EUR',
            'paypal_txn' => 'TXN-777',
        ]), $overrides);
    }

    /** Сбросить анти-флуд-лимит между двумя submit'ами одного теста. */
    private function clearRateLimit(): void
    {
        RateLimiter::clear('book-club-pay:127.0.0.1');
    }

    private function fakeTochka(): void
    {
        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/bookclub', 'paymentLinkId' => 'pl_bookclub'],
        ], 200)]);
    }

    // --- Флаг ---

    public function test_flag_off_returns_404_for_get_and_post(): void
    {
        // Пиним флаг ВЫКЛ явно: тест не должен зависеть от ambient .env разработчика
        // (в phpunit.xml BOOK_CLUB_DELIVERY не задан).
        config(['features.book_club_delivery' => false]);

        $this->get(route('book-club.show'))->assertNotFound();
        $this->post(route('book-club.submit'), $this->rfPayload())->assertNotFound();

        $this->assertDatabaseMissing('payments', ['tariff' => 'book_club_delivery']);
        $this->assertDatabaseCount('book_club_orders', 0);
    }

    public function test_flag_on_shows_checkout_with_tariff_table(): void
    {
        $this->enableFlag();

        $this->get(route('book-club.show'))
            ->assertOk()
            ->assertSee('Книжный клуб')
            ->assertSee('300')      // РФ
            ->assertSee('5 100')    // Латвия
            ->assertSee('6 600');   // другая страна
    }

    // --- РФ: Tochka ---

    public function test_rf_checkout_creates_pending_payment_and_redirects_to_tochka(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $this->post(route('book-club.submit'), $this->rfPayload())
            ->assertRedirect('https://pay.tochka/bookclub');

        // Платёж: доставка, без курса, без выдачи доступа.
        $this->assertDatabaseHas('payments', [
            'tariff' => 'book_club_delivery',
            'status' => 'pending',
            'amount' => 300,
            'course_id' => null,
            'transaction_id' => 'pl_bookclub',
        ]);

        // Заказ: адрес + планета-матчинг + цена.
        $this->assertDatabaseHas('book_club_orders', [
            'planeta_email' => 'backer@mozmail.com',
            'planeta_order_number' => '20241121-4433387',
            'recipient_name' => 'Иванов Иван Иванович',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, д. 1, кв. 1',
            'amount' => 300,
        ]);

        $this->assertDatabaseHas('users', ['email' => 'buyer@example.test']);
    }

    public function test_rf_price_is_300_regardless_of_user_discount_machinery(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        // Залогиненный пользователь со скидками — цена доставки всё равно 300.
        $user = User::factory()->create(['email' => 'loyal@example.test']);
        $this->actingAs($user);

        $this->post(route('book-club.submit'), $this->rfPayload(['email' => 'loyal@example.test']))
            ->assertRedirect('https://pay.tochka/bookclub');

        $this->assertDatabaseHas('payments', [
            'tariff' => 'book_club_delivery',
            'amount' => 300,
            'discount_percent' => null,
        ]);
    }

    // --- Заграница: PayPal-заявка ---

    public function test_abroad_checkout_creates_paypal_claim_and_notifies(): void
    {
        $this->enableFlag();
        config(['services.admin.email' => 'admin@example.test']);
        Http::fake(); // Tochka вызываться НЕ должна

        $this->post(route('book-club.submit'), $this->abroadPayload())
            ->assertRedirect(route('book-club.show'))
            ->assertSessionHas('success');

        // Тариф Латвии — 5100 ₽ (сверка с README.md).
        $this->assertDatabaseHas('payments', [
            'tariff' => 'book_club_delivery',
            'status' => 'pending',
            'amount' => 5100,
            'course_id' => null,
            'provider' => Payment::PROVIDER_PAYPAL,
            'foreign_amount' => 55,
            'foreign_currency' => 'EUR',
        ]);

        $this->assertDatabaseHas('book_club_orders', [
            'country_code' => 'LV',
            'amount' => 5100,
            'planeta_order_number' => '20241121-4433387',
        ]);

        Mail::assertQueued(PaypalClaimReceivedMail::class);
    }

    public function test_other_country_pays_6600(): void
    {
        $this->enableFlag();
        Http::fake();

        $this->post(route('book-club.submit'), $this->abroadPayload([
            'country_code' => 'OTHER',
            'country_name' => 'Франция',
            'foreign_amount' => 70,
        ]))->assertRedirect(route('book-club.show'));

        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_delivery', 'amount' => 6600]);
        $this->assertDatabaseHas('book_club_orders', ['country_code' => 'OTHER', 'country_name' => 'Франция', 'amount' => 6600]);
    }

    // --- Валидация ---

    public function test_planeta_fields_and_address_are_required(): void
    {
        $this->enableFlag();

        $this->from(route('book-club.show'))
            ->post(route('book-club.submit'), $this->rfPayload([
                'planeta_order_number' => '',
                'postal_code' => '',
                'recipient_name' => '',
            ]))
            ->assertSessionHasErrors(['planeta_order_number', 'postal_code', 'recipient_name']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('book_club_orders', 0);
    }

    public function test_abroad_requires_paypal_proof(): void
    {
        $this->enableFlag();

        $this->from(route('book-club.show'))
            ->post(route('book-club.submit'), $this->abroadPayload([
                'foreign_amount' => '',
                'foreign_currency' => 'CHF',
                'paypal_txn' => '',
            ]))
            ->assertSessionHasErrors(['foreign_amount', 'foreign_currency', 'paypal_txn']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_other_country_requires_country_name(): void
    {
        $this->enableFlag();

        $this->from(route('book-club.show'))
            ->post(route('book-club.submit'), $this->abroadPayload([
                'country_code' => 'OTHER',
                'country_name' => '',
            ]))
            ->assertSessionHasErrors('country_name');

        $this->assertDatabaseCount('payments', 0);
    }

    // --- Анти-takeover и анти-дубль ---

    public function test_guest_with_existing_email_is_rejected(): void
    {
        $this->enableFlag();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->from(route('book-club.show'))
            ->post(route('book-club.submit'), $this->rfPayload(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_duplicate_planeta_order_is_not_charged_twice(): void
    {
        $this->enableFlag();
        $this->fakeTochka();

        $this->post(route('book-club.submit'), $this->rfPayload())->assertRedirect('https://pay.tochka/bookclub');

        // Повторная отправка того же заказа planeta (другой email аккаунта):
        // второй платёж НЕ создаётся, второй аккаунт — тоже.
        $this->clearRateLimit();

        $this->post(route('book-club.submit'), $this->rfPayload([
            'email' => 'second@example.test',
        ]))->assertSessionHas('error');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('book_club_orders', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_tochka_unavailable_marks_payment_failed(): void
    {
        $this->enableFlag();

        // Tochka недоступна (сетевой сбой) → платёж failed, мягкая ошибка.
        Http::fake(['*' => fn () => throw new ConnectionException('down')]);

        $this->post(route('book-club.submit'), $this->rfPayload())
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payments', ['tariff' => 'book_club_delivery', 'status' => 'failed']);
    }

    public function test_failed_payment_allows_resubmit(): void
    {
        $this->enableFlag();

        // Первая попытка «до нас»: платёж failed (см. тест выше) — заказ висит.
        $user = User::factory()->create(['email' => 'first@example.test']);
        $failed = Payment::create([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => 300,
            'tariff' => 'book_club_delivery',
            'status' => 'failed',
        ]);
        BookClubOrder::create([
            'payment_id' => $failed->id,
            'user_id' => $user->id,
            'planeta_email' => 'backer@mozmail.com',
            'planeta_order_number' => '20241121-4433387',
            'recipient_name' => 'Иванов Иван Иванович',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, д. 1, кв. 1',
            'amount' => 300,
        ]);

        // Повтор разрешён: failed не считается активным заказом.
        $this->fakeTochka();

        $this->post(route('book-club.submit'), $this->rfPayload(['email' => 'retry@example.test']))
            ->assertRedirect('https://pay.tochka/bookclub');

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('book_club_orders', 2);
    }
}
