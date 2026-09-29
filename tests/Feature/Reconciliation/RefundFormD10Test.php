<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Filament\Resources\PaymentResource\Pages\CreatePayment;
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
 * H5445 (D10): админ-форма возврата показывает отказ «частичный без блоков»
 * ошибкой поля, а не падением сохранения.
 */
class RefundFormD10Test extends TestCase
{
    use RefreshDatabase;

    private Payment $original;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $this->original = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => '12000.00',
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 3,
            'status' => 'paid',
            'is_conditional' => false,
            'first_paid_at' => now(),
        ]));
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->original->user_id,
            'course_id' => $this->original->course_id,
            'tariff' => 'Расход',
            'amount' => '-4000',
            'status' => 'paid',
            'refund_of_payment_id' => $this->original->id,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    public function test_partial_refund_without_blocks_is_a_form_error_when_flag_on(): void
    {
        config(['features.money_refund_access_rules' => true]);

        Livewire::test(CreatePayment::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertHasFormErrors(['refund_of_payment_id']);

        $this->assertSame(0, Payment::query()->whereNotNull('refund_of_payment_id')->count());
    }

    public function test_partial_refund_with_blocks_passes_the_rule(): void
    {
        config(['features.money_refund_access_rules' => true]);

        Livewire::test(CreatePayment::class)
            ->fillForm($this->form(['start_block' => 3, 'end_block' => 3]))
            ->call('create')
            ->assertHasNoFormErrors(['refund_of_payment_id']);
    }

    public function test_full_refund_needs_no_blocks(): void
    {
        config(['features.money_refund_access_rules' => true]);

        Livewire::test(CreatePayment::class)
            ->fillForm($this->form(['amount' => '-12000']))
            ->call('create')
            ->assertHasNoFormErrors(['refund_of_payment_id']);
    }

    public function test_flag_off_leaves_the_form_as_before(): void
    {
        config(['features.money_refund_access_rules' => false]);

        Livewire::test(CreatePayment::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertHasNoFormErrors(['refund_of_payment_id']);
    }
}
