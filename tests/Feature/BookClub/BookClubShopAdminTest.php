<?php

declare(strict_types=1);

namespace Tests\Feature\BookClub;

use App\Filament\Resources\BookClubOrderResource;
use App\Filament\Resources\BookClubOrderResource\Pages\ListBookClubOrders;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\BookResource\Pages\ListBooks;
use App\Models\Book;
use App\Models\BookClubOrder;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Админка магазина (H8412): оба ресурса (книги + заказы) живут за флагом
 * features.book_club_shop и только для админов; список заказов и остаток книг
 * открываются без ошибок схемы.
 */
class BookClubShopAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_resources_are_hidden_while_flag_is_off(): void
    {
        config(['features.book_club_shop' => false]);

        $this->actingAs($this->user(Roles::ADMIN));

        $this->assertFalse(BookResource::canViewAny());
        $this->assertFalse(BookClubOrderResource::canViewAny());
        $this->assertFalse(BookResource::shouldRegisterNavigation());
    }

    public function test_only_admins_see_resources_when_flag_is_on(): void
    {
        config(['features.book_club_shop' => true]);

        foreach ([Roles::SUPER_ADMIN, Roles::ADMIN] as $role) {
            $this->actingAs($this->user($role));
            $this->assertTrue(BookResource::canViewAny(), $role.' должен видеть книги');
            $this->assertTrue(BookClubOrderResource::canViewAny(), $role.' должен видеть заказы');
        }

        foreach ([Roles::MANAGER, Roles::ACCOUNTANT, Roles::TEACHER, null] as $role) {
            $this->actingAs($this->user($role));
            $this->assertFalse(BookResource::canViewAny());
            $this->assertFalse(BookClubOrderResource::canViewAny());
        }
    }

    public function test_orders_are_never_created_manually(): void
    {
        config(['features.book_club_shop' => true]);
        $this->actingAs($this->user(Roles::ADMIN));

        $this->assertFalse(BookClubOrderResource::canCreate());
    }

    public function test_books_list_renders_catalog_with_stock(): void
    {
        config(['features.book_club_shop' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($this->user(Roles::ADMIN));

        $book = Book::query()->firstOrFail();

        Livewire::test(ListBooks::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$book])
            ->assertSee($book->title);
    }

    public function test_orders_list_renders_shop_and_campaign_orders(): void
    {
        config(['features.book_club_shop' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create(['email' => 'buyer@example.test']);
        $book = Book::query()->firstOrFail();

        $purchase = BookClubOrder::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'mode' => BookClubOrder::MODE_PURCHASE,
            'books_count' => 2,
            'recipient_name' => 'Иванов Иван',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, 1',
            'amount' => 2300,
            'delivery_amount' => 300,
            'status' => BookClubOrder::STATUS_PAID,
        ]);

        $campaign = BookClubOrder::create([
            'user_id' => $user->id,
            'mode' => BookClubOrder::MODE_DELIVERY_ONLY,
            'planeta_email' => 'backer@mozmail.com',
            'planeta_order_number' => '20241121-4433387',
            'recipient_name' => 'Иванов Иван',
            'country_code' => 'RU',
            'city' => 'Москва',
            'postal_code' => '119019',
            'address_line' => 'Арбат, 1',
            'amount' => 300,
            'delivery_amount' => 300,
            'status' => BookClubOrder::STATUS_PENDING,
        ]);

        $this->actingAs($this->user(Roles::ADMIN));

        Livewire::test(ListBookClubOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$purchase, $campaign]);
    }
}
