<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Онлайн-приём пожертвований на /mecenaty через Точку (план института N2).
 *
 * Контурные инварианты:
 *  - флаг institute.donations_enabled default OFF → 404 и страница-реквизиты;
 *  - платёж tariff=donation без course_id;
 *  - paid-переход НЕ выдаёт доступ/членство/лид-конверсию/партнёрскую награду
 *    (fireOnPaid выходит сразу — донорская рамка без встречных благ).
 */
class InstituteDonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_is_404_while_flag_off(): void
    {
        $this->post(route('institute.donate'), ['amount' => 500])
            ->assertNotFound();
    }

    public function test_page_stays_requisites_only_while_flag_off(): void
    {
        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('Перевод по реквизитам')
            ->assertDontSee('Свободная сумма');
    }

    public function test_flag_on_shows_online_form(): void
    {
        config(['institute.donations_enabled' => true]);

        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('Свободная сумма')
            ->assertSee(route('institute.donate'));
    }

    public function test_guest_donation_creates_pending_payment_and_redirects_to_tochka(): void
    {
        config(['institute.donations_enabled' => true]);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/don',
                    'paymentLinkId' => 'tochka_don_001',
                ],
            ], 200),
        ]);

        $response = $this->post(route('institute.donate'), [
            'amount' => 1500,
            'name' => 'Меценат',
            'email' => 'mecenate@example.test',
        ]);

        $response->assertRedirect('https://pay.tochka.com/redirect/don');

        $user = User::where('email', 'mecenate@example.test')->firstOrFail();

        $payment = Payment::query()->where('tariff', 'donation')->latest()->firstOrFail();
        $this->assertSame($user->id, $payment->user_id);
        $this->assertNull($payment->course_id);
        $this->assertSame(1500.0, (float) $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('tochka_don_001', $payment->transaction_id);
    }

    public function test_auth_user_does_not_need_name_email(): void
    {
        config(['institute.donations_enabled' => true]);

        $user = User::factory()->create();
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/don2',
                    'paymentLinkId' => 'tochka_don_002',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('institute.donate'), ['amount' => 2000])
            ->assertRedirect('https://pay.tochka.com/redirect/don2');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'tariff' => 'donation',
            'status' => 'pending',
            'amount' => 2000,
        ]);
    }

    public function test_guest_with_existing_email_is_refused(): void
    {
        config(['institute.donations_enabled' => true]);

        User::factory()->create(['email' => 'taken@example.test']);

        $this->from('/mecenaty')
            ->post(route('institute.donate'), [
                'amount' => 500,
                'name' => 'Кто-то',
                'email' => 'taken@example.test',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('payments', ['tariff' => 'donation']);
    }

    public function test_amount_bounds_are_enforced(): void
    {
        config(['institute.donations_enabled' => true]);

        foreach ([50, 400000] as $bad) {
            $this->post(route('institute.donate'), [
                'amount' => $bad,
                'name' => 'Меценат',
                'email' => 'bounds@example.test',
            ])->assertSessionHasErrors('amount');
        }

        $this->assertDatabaseMissing('payments', ['tariff' => 'donation']);
    }

    public function test_paid_donation_grants_nothing(): void
    {
        config(['institute.donations_enabled' => true]);

        $user = User::factory()->create();

        // Лид с тем же email существует — пожертвование НЕ должно конвертировать его.
        $lead = Lead::create([
            'name' => 'Лид',
            'contact' => 'tg://lead',
            'email' => $user->email,
            'is_promo_agreed' => true,
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => 1000,
            'tariff' => 'donation',
            'status' => 'pending',
        ]);

        // Имитируем APPROVED-вебхук Точки.
        $payment->update(['status' => 'paid']);

        $payment = $payment->fresh();
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->first_paid_at, 'first_paid_at должен штамповаться и у пожертвования.');

        // Донорская рамка: ни доступа, ни членства, ни лид-конверсии, ни партнёрской награды.
        $this->assertSame(0, $user->groups()->count());
        $this->assertDatabaseCount('club_memberships', 0);
        $this->assertNull($lead->fresh()->converted_at);
        $this->assertDatabaseCount('partner_conversions', 0);
        $this->assertNull($payment->fresh()->deposit_consumed_at);
    }
}
