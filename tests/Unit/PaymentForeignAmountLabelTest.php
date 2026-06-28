<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Справочная валютная сумма (USD/EUR) для колонки «Примечание» отчёта.
 * В рублёвых расчётах не участвует — только форматирование подписи.
 */
class PaymentForeignAmountLabelTest extends TestCase
{
    /** @test */
    public function formats_usd_and_eur(): void
    {
        $this->assertSame('50.00 $', (new Payment([
            'foreign_amount' => 50, 'foreign_currency' => 'USD',
        ]))->foreignAmountLabel());

        $this->assertSame('45.50 €', (new Payment([
            'foreign_amount' => 45.5, 'foreign_currency' => 'EUR',
        ]))->foreignAmountLabel());
    }

    /** @test */
    public function empty_when_amount_or_currency_missing(): void
    {
        $this->assertSame('', (new Payment(['foreign_currency' => 'USD']))->foreignAmountLabel());
        $this->assertSame('', (new Payment(['foreign_amount' => 50]))->foreignAmountLabel());
        $this->assertSame('', (new Payment([]))->foreignAmountLabel());
    }
}
