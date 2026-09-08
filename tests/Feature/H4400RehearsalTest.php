<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** H4400 checkout pre-flight rehearsal on staff user — Tochka faked, zero real charges. */
final class H4400RehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_checkout_rehearsal_three_skus(): void
    {
        Config::set('institute.donations_enabled', true);
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/REHEARSAL-H4400', 'paymentLinkId' => 'rehearsal_h4400'],
            ], 200),
        ]);

        $staff = User::factory()->create(['role' => 'super_admin']);

        foreach (['monthly' => 500, 'yearly' => 5000, 'student' => 250] as $sku => $expected) {
            $this->actingAs($staff)
                ->post(route('institute.donate'), ['sku' => $sku])
                ->assertRedirect('https://pay.tochka.com/redirect/REHEARSAL-H4400');

            $payment = Payment::where('user_id', $staff->id)->where('amount', $expected)->latest()->firstOrFail();
            $this->assertSame('donation', $payment->tariff);
            $this->assertSame('pending', $payment->status);
            $this->assertNull($payment->course_id);

            // Full paid-transition on staff user: observer chain, gratitude absent → no-op.
            $payment->update(['status' => 'paid']);
            $this->assertSame('paid', $payment->fresh()->status);
            $this->assertNotNull($payment->fresh()->first_paid_at);
            // Донорская рамка: доступа/членства SKU не несёт.
            $this->assertSame(0, $staff->groups()->count());

            // Rehearsal rows removed (H3331 hard guard #3).
            $payment->delete();
        }

        $this->assertDatabaseCount('payments', 0);
    }
}
