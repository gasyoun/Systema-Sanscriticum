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
 * PayPal-канал в «Способе оплаты» (H226-канон расширен): валютная заявка
 * студента (provider=paypal) и авто-списание подписки (paypal_subscription)
 * помечаются payment_method='paypal' моделью при сохранении — раньше колонка
 * была пуста для всего, что не пришло с вебхука Точки, и PayPal-платежи
 * отображались как «Не определён». Прочие провайдеры (invoice, bank_sepa…)
 * не трогаются.
 */
class PaymentPaypalMethodTest extends TestCase
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

    private function payment(array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'pending',
        ], $attributes));
    }

    /** @test */
    public function paypal_provider_marks_payment_method_on_save(): void
    {
        $claim = $this->payment(['provider' => 'paypal', 'foreign_currency' => 'USD', 'foreign_amount' => 55]);
        $subscription = $this->payment(['provider' => 'paypal_subscription', 'foreign_currency' => 'USD', 'foreign_amount' => 55]);
        $invoice = $this->payment(['provider' => 'invoice']);
        $tochka = $this->payment(['payment_method' => 'card']);

        $this->assertSame('paypal', $claim->payment_method);
        $this->assertSame('paypal', $subscription->payment_method);
        // Прочие провайдеры и платежи Точки не трогаются.
        $this->assertNull($invoice->payment_method);
        $this->assertSame('card', $tochka->payment_method);
    }

    /** @test */
    public function method_filter_separates_paypal_from_unknown(): void
    {
        $paypal = $this->payment(['provider' => 'paypal']);
        $card = $this->payment(['payment_method' => 'card']);
        $manual = $this->payment();

        Livewire::test(ListPayments::class)
            ->assertOk()
            ->filterTable('payment_method', 'paypal')
            ->assertCanSeeTableRecords([$paypal])
            ->assertCanNotSeeTableRecords([$card, $manual])
            // «Не определён» — NULL: ручные платежи и старые вебхуки.
            ->filterTable('payment_method', 'unknown')
            ->assertCanSeeTableRecords([$manual])
            ->assertCanNotSeeTableRecords([$paypal, $card]);
    }
}
