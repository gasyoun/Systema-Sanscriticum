<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H226 — способ оплаты Точки в списке транзакций: колонка-бейдж + фильтр
 * card/sbp/«Не определён» (NULL = ручные платежи, PayPal, старые вебхуки).
 */
class PaymentMethodTrackingTest extends TestCase
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

    private function payment(?string $method): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'pending', // pending → без fireOnPaid-побочек
            'payment_method' => $method,
        ]);
    }

    /** @test */
    public function payment_method_filter_separates_card_sbp_and_unknown(): void
    {
        $card = $this->payment('card');
        $sbp = $this->payment('sbp');
        $manual = $this->payment(null);

        Livewire::test(ListPayments::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$card, $sbp, $manual])
            ->filterTable('payment_method', 'card')
            ->assertCanSeeTableRecords([$card])
            ->assertCanNotSeeTableRecords([$sbp, $manual])
            ->filterTable('payment_method', 'sbp')
            ->assertCanSeeTableRecords([$sbp])
            ->assertCanNotSeeTableRecords([$card, $manual])
            // «Не определён» — NULL: ручной платёж / PayPal / вебхук до поля.
            ->filterTable('payment_method', 'unknown')
            ->assertCanSeeTableRecords([$manual])
            ->assertCanNotSeeTableRecords([$card, $sbp]);
    }
}
