<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FinanceSnapshot;
use App\Models\Payment;
use App\Models\TeacherPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3532 — finance_snapshots: единственная пишущая поверхность годового календаря.
 * Ручной ввод баланса PayPal с датой; money-таблицы не затрагиваются никогда.
 */
class FinanceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function stores_minor_units_with_roundtrip(): void
    {
        $snap = FinanceSnapshot::query()->create([
            'type' => 'paypal_balance',
            'amount_minor' => FinanceSnapshot::toMinor(1250.55),
            'currency' => 'EUR',
            'entered_at' => now(),
        ]);

        $this->assertSame(125055, $snap->amount_minor);
        $this->assertSame(1250.55, $snap->majorAmount());
        $this->assertSame('EUR', $snap->currency);
    }

    /** @test */
    public function latest_of_type_returns_freshest_entry(): void
    {
        $older = FinanceSnapshot::query()->create([
            'type' => 'paypal_balance',
            'amount_minor' => 100,
            'currency' => 'EUR',
            'entered_at' => now()->subDays(3),
        ]);
        $newer = FinanceSnapshot::query()->create([
            'type' => 'paypal_balance',
            'amount_minor' => 200,
            'currency' => 'EUR',
            'entered_at' => now(),
        ]);
        FinanceSnapshot::query()->create([
            'type' => 'fx_eur_rub',
            'amount_minor' => 8685,
            'currency' => 'RUB',
            'entered_at' => now(),
        ]);

        $this->assertSame($newer->id, FinanceSnapshot::latestOfType('paypal_balance')?->id);
        $this->assertNotSame($older->id, FinanceSnapshot::latestOfType('paypal_balance')?->id);

        $fx = FinanceSnapshot::latestOfType('fx_eur_rub');
        $this->assertNotNull($fx);
        $this->assertSame(86.85, $fx->majorAmount());
    }

    /** @test */
    public function user_is_optional_and_dated_entry_is_kept(): void
    {
        $user = User::factory()->create();

        $snap = FinanceSnapshot::query()->create([
            'type' => 'paypal_balance',
            'amount_minor' => 500,
            'currency' => 'EUR',
            'entered_at' => '2026-08-20 12:00:00',
            'user_id' => $user->id,
            'note' => 'ввёл бухгалтер',
        ]);

        $this->assertSame($user->id, $snap->user_id);
        $this->assertSame($snap->user->id, $user->id);
        $this->assertSame('2026-08-20', $snap->entered_at?->toDateString());

        $anon = FinanceSnapshot::query()->create([
            'type' => 'paypal_balance',
            'amount_minor' => 600,
            'currency' => 'EUR',
            'entered_at' => now(),
        ]);
        $this->assertNull($anon->user_id);
    }

    /** @test */
    public function snapshots_never_touch_money_tables(): void
    {
        $before = [
            'payouts' => TeacherPayout::query()->count(),
            'payments' => Payment::query()->count(),
            'users' => User::query()->count(),
        ];

        FinanceSnapshot::query()->create([
            'type' => 'paypal_balance',
            'amount_minor' => 700,
            'currency' => 'EUR',
            'entered_at' => now(),
        ]);

        $after = [
            'payouts' => TeacherPayout::query()->count(),
            'payments' => Payment::query()->count(),
            'users' => User::query()->count(),
        ];

        $this->assertSame($before, $after);
    }
}
