<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function makePayment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
        ], $overrides));
    }

    /** @test */
    public function creating_a_payment_logs_a_create_audit(): void
    {
        $payment = $this->makePayment();

        $audit = PaymentAudit::where('payment_id', $payment->id)
            ->where('action', PaymentAudit::ACTION_CREATED)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('5000.00', (string) $audit->amount);
        $this->assertSame('Система', $audit->admin_name); // нет авторизованного пользователя
        $this->assertSame('full', $audit->changes['tariff']);
    }

    /** @test */
    public function admin_is_recorded_when_authenticated(): void
    {
        $admin = User::factory()->create(['name' => 'Админ Иван']);
        $this->actingAs($admin);

        $payment = $this->makePayment();

        $audit = PaymentAudit::where('payment_id', $payment->id)->latest('id')->first();
        $this->assertSame($admin->id, $audit->admin_id);
        $this->assertSame('Админ Иван', $audit->admin_name);
    }

    /** @test */
    public function updating_significant_field_logs_diff(): void
    {
        $payment = $this->makePayment(['amount' => 5000]);
        PaymentAudit::query()->delete(); // оставляем только апдейт

        $payment->update(['amount' => 0]);

        $audit = PaymentAudit::where('action', PaymentAudit::ACTION_UPDATED)->first();
        $this->assertNotNull($audit);
        // diff хранит пару [было, стало] как сырые значения столбца
        $this->assertSame('5000', (string) $audit->changes['amount'][0]);
        $this->assertSame('0', (string) $audit->changes['amount'][1]);
    }

    /** @test */
    public function touching_only_insignificant_fields_writes_no_update_audit(): void
    {
        $payment = $this->makePayment();
        PaymentAudit::query()->delete();

        // Меняем служебное поле, не входящее в AUDITED.
        $payment->forceFill(['prana_spent' => 10])->save();

        $this->assertSame(0, PaymentAudit::where('action', PaymentAudit::ACTION_UPDATED)->count());
    }

    /** @test */
    public function deleting_a_payment_logs_a_delete_audit_that_survives(): void
    {
        $payment = $this->makePayment();
        $id = $payment->id;

        $payment->delete();

        $audit = PaymentAudit::where('payment_id', $id)
            ->where('action', PaymentAudit::ACTION_DELETED)
            ->first();

        $this->assertNotNull($audit, 'Аудит удаления должен пережить сам платёж.');
        $this->assertNull(Payment::find($id));
    }
}
