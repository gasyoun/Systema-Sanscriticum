<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Filament\Resources\PaymentResource;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5084 regression · payment-hard-delete-skips-access-revocation.
 *
 * Same paid-payment setup as the NV-13 proof, assertions flipped to the
 * fixed invariant: the Filament delete gates (single DeleteAction on the
 * edit page → canDelete, DeleteBulkAction → canDeleteAny) must refuse
 * manager/accountant — revocation is keyed to status transitions only, so
 * the money row must not be deletable by roles below the admin trust floor.
 */
class H5084PaymentDeleteAdminOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    private function paidPayment(): Payment
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'H5084 группа']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'status' => 'paid',
        ]);

        // The paid transition really granted the benefit the delete would
        // strand (this is what makes the row money-critical).
        $this->assertTrue($student->fresh()->groups->contains($group->id), 'group granted on paid');

        return $payment;
    }

    /** @test */
    public function manager_and_accountant_cannot_delete_payments(): void
    {
        $payment = $this->paidPayment();

        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->assertFalse(PaymentResource::canDelete($payment), 'manager must not delete a payment row');
        $this->assertFalse(PaymentResource::canDeleteAny(), 'manager must not bulk-delete payment rows');

        $this->actingAs(User::factory()->create(['role' => 'accountant']));
        $this->assertFalse(PaymentResource::canDelete($payment), 'accountant must not delete a payment row');
        $this->assertFalse(PaymentResource::canDeleteAny(), 'accountant must not bulk-delete payment rows');
    }

    /** @test */
    public function admin_and_super_admin_still_can_delete(): void
    {
        $payment = $this->paidPayment();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertTrue(PaymentResource::canDelete($payment));
        $this->assertTrue(PaymentResource::canDeleteAny());

        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->assertTrue(PaymentResource::canDelete($payment));
        $this->assertTrue(PaymentResource::canDeleteAny());
    }

    /** @test */
    public function guest_cannot_delete(): void
    {
        $payment = $this->paidPayment();

        auth()->logout();
        $this->assertFalse(PaymentResource::canDelete($payment));
        $this->assertFalse(PaymentResource::canDeleteAny());
    }

    /** @test */
    public function other_manager_surface_permissions_are_unchanged(): void
    {
        // canViewAny/canEdit stay open to manager/accountant (only removal is
        // gated) — the fix must not narrow the read/edit workflow.
        $this->actingAs(User::factory()->create(['role' => 'manager']));

        $this->assertTrue(PaymentResource::canViewAny());
        $this->assertTrue(PaymentResource::canEdit($this->paidPayment()));
    }
}
