<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Partner;
use App\Models\PartnerConversion;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Процентная модель партнёрского вознаграждения (решение MG 23-08, вариант А
 * для программы со студиями: 10 % от первого реального платежа).
 *
 * Приоритет ставки: персональный override > глобальный процент > фикс.
 */
class PartnerRewardPercentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'partner.enabled' => true,
            'partner.reward_amount' => 1000,
            'partner.reward_percent' => 10.0,
        ]);
    }

    private function activePartner(?float $override = null): Partner
    {
        return Partner::create(array_filter([
            'name' => 'Студия йоги',
            'telegram_username' => '@studio',
            'code' => Partner::generateCode(),
            'status' => Partner::STATUS_ACTIVE,
            'reward_amount_override' => $override,
        ], fn ($v) => $v !== null));
    }

    private function paidPayment(User $client, float $amount): Payment
    {
        return Payment::create([
            'user_id' => $client->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => $amount,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
    }

    public function test_accrual_is_percent_of_payment(): void
    {
        $partner = $this->activePartner();
        $client = User::factory()->create();
        $client->forceFill(['partner_id' => $partner->id])->save();

        // Пилотный ПК «Санскрит» 30 000 ₽ → агентские 3 000 ₽.
        $this->paidPayment($client->fresh(), 30000);

        $conversion = PartnerConversion::firstOrFail();
        $this->assertSame(3000.0, (float) $conversion->reward_amount);
        $this->assertSame(PartnerConversion::STATUS_ACCRUED, $conversion->status);
    }

    public function test_rounds_to_kopecks(): void
    {
        $partner = $this->activePartner();
        $client = User::factory()->create();
        $client->forceFill(['partner_id' => $partner->id])->save();

        $this->paidPayment($client->fresh(), 1499.99);

        $conversion = PartnerConversion::firstOrFail();
        $this->assertSame(150.0, (float) $conversion->reward_amount);
    }

    public function test_personal_override_beats_percent(): void
    {
        $partner = $this->activePartner(override: 5000.0);
        $client = User::factory()->create();
        $client->forceFill(['partner_id' => $partner->id])->save();

        $this->paidPayment($client->fresh(), 30000);

        $conversion = PartnerConversion::firstOrFail();
        $this->assertSame(5000.0, (float) $conversion->reward_amount);
    }

    public function test_zero_percent_falls_back_to_fixed_amount(): void
    {
        config(['partner.reward_percent' => 0]);

        $partner = $this->activePartner();
        $client = User::factory()->create();
        $client->forceFill(['partner_id' => $partner->id])->save();

        $this->paidPayment($client->fresh(), 30000);

        $conversion = PartnerConversion::firstOrFail();
        $this->assertSame(1000.0, (float) $conversion->reward_amount);
    }

    public function test_landing_advertises_percent_terms(): void
    {
        $response = $this->get('/partners');

        $response->assertOk()
            ->assertSee('10 %', false)
            ->assertSee('от первого платежа', false)
            ->assertDontSee('1 000 ₽ за каждого приведенного клиента', false);
    }
}
