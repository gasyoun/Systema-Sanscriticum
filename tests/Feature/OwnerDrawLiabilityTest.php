<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OwnerDrawLiability;
use App\Models\OwnerDrawPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Обязательства перед владельцем (H4188 п.3/4): посев реестра 05-09
 * (февральская нота), пара «выплачено / остаток», append-only выплаты.
 */
class OwnerDrawLiabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function registry_rows_are_seeded_by_migration(): void
    {
        $eur = OwnerDrawLiability::query()->where('currency', 'EUR')->firstOrFail();
        $usd = OwnerDrawLiability::query()->where('currency', 'USD')->firstOrFail();
        $rub = OwnerDrawLiability::query()->where('currency', 'RUB')->firstOrFail();

        $this->assertEquals('1606.76', $eur->principal);
        $this->assertEquals('38.00', $usd->principal);
        $this->assertEquals('44183.19', $rub->principal);

        foreach ([$eur, $usd, $rub] as $row) {
            $this->assertEquals('2026-02-28', $row->fixed_at->toDateString());
            $this->assertEquals('0.00', $row->paid);
            $this->assertEquals((string) $row->principal, $row->remaining);
        }
    }

    /** @test */
    public function appending_payment_updates_paid_remaining_pair(): void
    {
        $eur = OwnerDrawLiability::query()->where('currency', 'EUR')->firstOrFail();

        OwnerDrawPayment::query()->create([
            'owner_draw_liability_id' => $eur->id,
            'amount' => '489.24',
            'paid_at' => '2026-03-05',
            'reference' => 'PayPal выписка март',
        ]);

        $eur->refresh();
        $this->assertEquals('489.24', $eur->paid);
        $this->assertEquals('1117.52', $eur->remaining);
    }

    /** @test */
    public function payment_records_are_append_only(): void
    {
        $eur = OwnerDrawLiability::query()->where('currency', 'EUR')->firstOrFail();

        $payment = OwnerDrawPayment::query()->create([
            'owner_draw_liability_id' => $eur->id,
            'amount' => '100.00',
            'paid_at' => '2026-03-05',
        ]);

        $this->expectException(\RuntimeException::class);
        $payment->update(['amount' => '999.00']);
    }
}
