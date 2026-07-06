<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource\Pages\EditPayment;
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
 * H222 D4 — гейт на выдачу логина ассистенту: менеджер не видит салярных/выплатных
 * полей платежа (salary_recognition_month, received_account, payer_note), админ — видит.
 */
class PaymentFieldMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function payment(): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'pending', // pending → без fireOnPaid-побочек
        ]);
    }

    /** @test */
    public function manager_cannot_see_salary_or_payout_fields(): void
    {
        $payment = $this->payment();
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));

        Livewire::test(EditPayment::class, ['record' => $payment->id])
            ->assertFormFieldIsHidden('salary_recognition_month')
            ->assertFormFieldIsHidden('received_account')
            ->assertFormFieldIsHidden('payer_note');
    }

    /** @test */
    public function admin_can_see_salary_and_payout_section(): void
    {
        $payment = $this->payment();
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(EditPayment::class, ['record' => $payment->id])
            ->assertFormFieldIsVisible('salary_recognition_month')
            ->assertFormFieldIsVisible('received_account');
    }
}
