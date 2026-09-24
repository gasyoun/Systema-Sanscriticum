<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Payments\PaypalClaimAmountCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Доплата за блок (H3990) под features.payment_fix_wave1: та же сверка суммы
 * (D4 + D20, ±5% в центах) и тот же ключ повтора claim_replay_key, что у
 * store() после H5442. Флаг OFF — прежний допуск ±0.5 и отказ валидацией.
 */
class PaypalSupplementClaimWaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');

        config([
            'services.paypal.enabled' => true,
            'services.paypal.me_link' => 'https://www.paypal.com/paypalme/school',
            'services.admin.email' => 'admin@example.test',
            'features.payment_fix_wave1' => true,
        ]);
    }

    private function blockTariff(): Tariff
    {
        return Tariff::factory()->for(Course::factory()->create())->block(3)->create(['price' => 8000]);
    }

    private function invoice(User $user, Tariff $tariff): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => 2000.00,
            'tariff' => '8000.00',
            'status' => 'pending',
            'provider' => 'manual',
            'payer_note' => 'Доплата за блок 3',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function submit(User $user, Tariff $tariff, float $amount, string $currency = 'EUR', array $extra = [])
    {
        return $this->actingAs($user)->post(route('paypal.claim.store', $tariff), $extra + [
            'supplement_mode' => '1',
            'foreign_amount' => $amount,
            'foreign_currency' => $currency,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-09-20',
        ]);
    }

    #[Test]
    public function exact_amount_closes_invoice_with_replay_key_and_documented_check(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->invoice($user, $tariff);

        $this->submit($user, $tariff, 26, 'USD', ['paypal_txn' => 'TXSUPEXACT'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->claim_replay_key);
        $this->assertSame(PaypalClaimAmountCheck::EXACT, $invoice->claim_meta['amount_check']['verdict']);
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function underpayment_within_five_percent_closes_invoice_and_notifies(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->invoice($user, $tariff);

        // 20.90 = 22 − 5.0% ровно: граница включительно.
        $this->submit($user, $tariff, 20.90)->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1.10 €'));

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(PaypalClaimAmountCheck::UNDERPAID_WITHIN, $invoice->claim_meta['amount_check']['verdict']);
        $this->assertEqualsWithDelta(-1.10, $invoice->claim_meta['amount_check']['diff'], 0.001);
    }

    #[Test]
    public function overpayment_within_five_percent_is_documented_without_debt_notice(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->invoice($user, $tariff);

        $this->submit($user, $tariff, 23)->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'добавьте эту разницу'));

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(PaypalClaimAmountCheck::OVERPAID_WITHIN, $invoice->claim_meta['amount_check']['verdict']);
        $this->assertEqualsWithDelta(1.0, $invoice->claim_meta['amount_check']['diff'], 0.001);
    }

    #[Test]
    public function beyond_five_percent_keeps_invoice_pending_and_records_exception(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->invoice($user, $tariff);

        // 20.89 = чуть больше 5% недоплаты; H4077-кейс 112 = 22 + 90 — тоже вне допуска.
        $this->submit($user, $tariff, 20.89)->assertSessionHasNoErrors()->assertRedirect();
        $this->submit($user, $tariff, 112)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('pending', $invoice->fresh()->status);
        $claims = Payment::whereKeyNot($invoice->id)->orderBy('id')->get();
        $this->assertCount(2, $claims);
        foreach ($claims as $claim) {
            $this->assertSame('pending', $claim->status);
            $this->assertSame(PaypalClaimAmountCheck::BEYOND, $claim->claim_meta['amount_check']['verdict']);
            $this->assertSame('supplement_amount_beyond_tolerance', $claim->claim_meta['reconciliation_exception']);
            $this->assertNotNull($claim->claim_replay_key);
        }
        $this->assertSame(0, Payment::where('status', 'paid')->count());
    }

    #[Test]
    public function replayed_supplement_is_rejected_before_write(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->invoice($user, $tariff);

        $this->submit($user, $tariff, 22, 'EUR', ['paypal_txn' => 'TXSUPREPLAY'])->assertSessionHasNoErrors();
        $this->submit($user, $tariff, 22, 'EUR', ['paypal_txn' => 'txsupreplay '])->assertSessionHasErrors('paypal_txn');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function replay_without_txn_is_rejected_and_claim_row_is_never_treated_as_invoice(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        // Счёта нет → pending-заявка. Повтор той же заявки без txn — отказ.
        $this->submit($user, $tariff, 22)->assertSessionHasNoErrors();
        $this->submit($user, $tariff, 22)->assertSessionHasErrors('paypal_txn');

        // Другая заявка (другой txn) не «закрывает» первую как счёт-доплату.
        $this->submit($user, $tariff, 22, 'EUR', ['paypal_txn' => 'TXSUPOTHER'])->assertSessionHasNoErrors();

        $this->assertSame(2, Payment::count());
        $this->assertSame(0, Payment::where('status', 'paid')->count());
    }

    #[Test]
    public function txn_already_claimed_for_the_block_cannot_be_reused_for_the_supplement(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->invoice($user, $tariff);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => 8000,
            'tariff' => $tariff->accessKey(),
            'status' => 'pending',
            'provider' => Payment::PROVIDER_PAYPAL,
            'claim_replay_key' => PaypalClaimAmountCheck::replayKey($user->id, $tariff->id, 'TXSHARED', '2026-09-20', 'EUR', 90),
            'claim_meta' => ['txn' => 'TXSHARED'],
        ]);

        $this->submit($user, $tariff, 22, 'EUR', ['paypal_txn' => 'TXSHARED'])->assertSessionHasErrors('paypal_txn');

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(2, Payment::count());
    }

    #[Test]
    public function supplement_scope_leaves_the_h5442_key_unchanged(): void
    {
        $legacy = hash('sha256', implode('|', ['paypal-claim|no-txn', 7, 3, '2026-09-20', 'EUR', 2200]));

        $this->assertSame($legacy, PaypalClaimAmountCheck::replayKey(7, 3, null, '2026-09-20', 'eur', 22.0));
        $this->assertNotSame($legacy, PaypalClaimAmountCheck::replayKey(7, 3, null, '2026-09-20', 'EUR', 22.0, 'supplement'));
        $this->assertSame(
            PaypalClaimAmountCheck::replayKey(7, 3, 'TX1', '2026-09-20', 'EUR', 22.0),
            PaypalClaimAmountCheck::replayKey(9, 4, 'tx1', '2026-09-21', 'USD', 26.0, 'supplement'),
        );
    }

    #[Test]
    public function flag_off_keeps_legacy_half_unit_tolerance_and_no_replay_key(): void
    {
        config(['features.payment_fix_wave1' => false]);
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->invoice($user, $tariff);

        // 20.90 — внутри ±5%, но вне прежних ±0.5 → отказ валидацией, как раньше.
        $this->submit($user, $tariff, 20.90)->assertSessionHasErrors('foreign_amount');
        $this->assertSame('pending', $invoice->fresh()->status);

        $this->submit($user, $tariff, 21.6)->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertNull($invoice->claim_replay_key);
        $this->assertArrayNotHasKey('amount_check', $invoice->claim_meta);
        $this->assertSame(1, Payment::count());
    }
}
