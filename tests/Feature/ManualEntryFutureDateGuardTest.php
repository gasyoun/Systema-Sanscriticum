<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\PaymentResource\Pages\CreatePayment;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Гард ручного ввода дат (issue #953, H2008): дата платежа и дата траты не могут
 * быть в будущем — две строки «Расход» пять месяцев висели в сентябре-2026 из-за
 * «10.09» вместо «10.02», и никакая валидация этого не ловила. Задним числом —
 * можно (легаси-бухгалтерия так и вносится), вперёд — нет.
 */
class ManualEntryFutureDateGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
    }

    /** @test */
    public function payment_form_rejects_future_date(): void
    {
        Livewire::test(CreatePayment::class)
            ->fillForm(['created_at' => now()->addMonth()->format('Y-m-d H:i:s')])
            ->call('create')
            ->assertHasFormErrors(['created_at']);
    }

    /** @test */
    public function payment_form_accepts_today_and_backdating(): void
    {
        Livewire::test(CreatePayment::class)
            ->fillForm(['created_at' => now()->format('Y-m-d H:i:s')])
            ->call('create')
            ->assertHasNoFormErrors(['created_at']);

        Livewire::test(CreatePayment::class)
            ->fillForm(['created_at' => now()->subMonths(5)->format('Y-m-d H:i:s')])
            ->call('create')
            ->assertHasNoFormErrors(['created_at']);
    }

    /** @test */
    public function expense_form_rejects_future_date(): void
    {
        Livewire::test(CreateExpense::class)
            ->fillForm(['spent_at' => now()->addMonth()->toDateString()])
            ->call('create')
            ->assertHasFormErrors(['spent_at']);
    }

    /** @test */
    public function expense_form_accepts_today_and_backdating(): void
    {
        Livewire::test(CreateExpense::class)
            ->fillForm(['spent_at' => now()->toDateString()])
            ->call('create')
            ->assertHasNoFormErrors(['spent_at']);

        Livewire::test(CreateExpense::class)
            ->fillForm(['spent_at' => now()->subMonths(5)->toDateString()])
            ->call('create')
            ->assertHasNoFormErrors(['spent_at']);
    }
}
