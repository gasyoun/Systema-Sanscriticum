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
 * card/sbp/dolyame/«Не определён» (NULL = ручные платежи, PayPal, старые
 * вебхуки). Канон значений — WebhookController::extractPaymentMethod().
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
        $dolyame = $this->payment('dolyame');
        $cash = $this->payment('cash');
        $manual = $this->payment(null);

        Livewire::test(ListPayments::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$card, $sbp, $dolyame, $cash, $manual])
            ->filterTable('payment_method', 'card')
            ->assertCanSeeTableRecords([$card])
            ->assertCanNotSeeTableRecords([$sbp, $dolyame, $cash, $manual])
            ->filterTable('payment_method', 'sbp')
            ->assertCanSeeTableRecords([$sbp])
            ->assertCanNotSeeTableRecords([$card, $dolyame, $cash, $manual])
            ->filterTable('payment_method', 'dolyame')
            ->assertCanSeeTableRecords([$dolyame])
            ->assertCanNotSeeTableRecords([$card, $sbp, $cash, $manual])
            ->filterTable('payment_method', 'cash')
            ->assertCanSeeTableRecords([$cash])
            ->assertCanNotSeeTableRecords([$card, $sbp, $dolyame, $manual])
            // «Не определён» — NULL: ручной платёж / PayPal / вебхук до поля.
            ->filterTable('payment_method', 'unknown')
            ->assertCanSeeTableRecords([$manual])
            ->assertCanNotSeeTableRecords([$card, $sbp, $dolyame, $cash]);
    }
}
