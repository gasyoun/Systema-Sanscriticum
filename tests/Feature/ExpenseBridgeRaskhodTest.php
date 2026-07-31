<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Support\FinanceCockpitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Мост «Расход» → opex-леджер (H2003, issue #953): expenses:bridge-raskhod
 * копирует легаси-платежи с тарифом «Расход» в Expense — идемпотентно
 * (payment_id уникален), с модулем суммы, датой платежа как spent_at и статьей
 * «Налоги, банк, прочее». Dry-run (без --apply) не пишет ничего.
 */
class ExpenseBridgeRaskhodTest extends TestCase
{
    use RefreshDatabase;

    private function raskhod(User $user, float $amount, string $createdAt): Payment
    {
        $p = Payment::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'tariff' => 'Расход',
            'status' => 'paid',
        ]);
        // created_at задаем отдельно: PaymentObserver/таймстампы перекрывают
        // значение из create-массива.
        $p->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $p->fresh();
    }

    /** @test */
    public function bridges_raskhod_payments_into_expense_ledger(): void
    {
        $user = User::factory()->create();
        $old = $this->raskhod($user, -50000, '2026-03-15 00:00:00');
        $new = $this->raskhod($user, -1500.50, '2026-06-02 10:10:00');

        $this->artisan('expenses:bridge-raskhod --apply')->assertSuccessful();

        $this->assertSame(2, Expense::count());

        $e = Expense::where('payment_id', $old->id)->first();
        $this->assertNotNull($e);
        $this->assertSame('50000.00', (string) $e->amount);
        $this->assertSame('2026-03-15', $e->spent_at->toDateString());
        $this->assertSame(ExpenseCategory::TaxesBankOther, $e->category);
        $this->assertStringContainsString("payment #{$old->id}", (string) $e->note);

        $this->assertSame('1500.50', (string) Expense::where('payment_id', $new->id)->value('amount'));
    }

    /** @test */
    public function rerun_is_idempotent_and_only_tops_up_new_rows(): void
    {
        $user = User::factory()->create();
        $this->raskhod($user, -100, '2026-05-01 00:00:00');

        $this->artisan('expenses:bridge-raskhod --apply')->assertSuccessful();
        $this->artisan('expenses:bridge-raskhod --apply')->assertSuccessful();
        $this->assertSame(1, Expense::count());

        $this->raskhod($user, -200, '2026-05-02 00:00:00');
        $this->artisan('expenses:bridge-raskhod --apply')->assertSuccessful();
        $this->assertSame(2, Expense::count());
    }

    /** @test */
    public function dry_run_writes_nothing(): void
    {
        $user = User::factory()->create();
        $this->raskhod($user, -100, '2026-05-01 00:00:00');

        $this->artisan('expenses:bridge-raskhod')->assertSuccessful();

        $this->assertSame(0, Expense::count());
    }

    /** @test */
    public function ignores_non_raskhod_and_unpaid_rows(): void
    {
        $user = User::factory()->create();
        Payment::create(['user_id' => $user->id, 'amount' => 4800, 'tariff' => 'full', 'status' => 'paid']);
        Payment::create(['user_id' => $user->id, 'amount' => -300, 'tariff' => 'Расход', 'status' => 'pending']);

        $this->artisan('expenses:bridge-raskhod --apply')->assertSuccessful();

        $this->assertSame(0, Expense::count());
    }

    /** @test */
    public function bridged_rows_feed_the_cockpit_window(): void
    {
        $user = User::factory()->create();
        $this->raskhod($user, -7000, '2026-05-10 00:00:00');

        $this->artisan('expenses:bridge-raskhod --apply')->assertSuccessful();

        [$start, $end] = app(FinanceCockpitReport::class)->monthBounds('2026-05');
        $this->assertSame(7000.0, Expense::totalForWindow($start, $end));
    }
}
