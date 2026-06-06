<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Единая человекочитаемая пометка операции (бронь / пробное / блок / курс / расход),
 * общая для админки (PaymentResource) и выгрузки в Google-таблицу.
 */
class PaymentOperationLabelTest extends TestCase
{
    /** @test */
    public function labels_deposit_trial_expense_and_full(): void
    {
        $this->assertSame('📌 Бронь курса (предоплата)', (new Payment(['tariff' => 'deposit']))->operationLabel());
        $this->assertSame('🎟 Пробное занятие', (new Payment(['tariff' => 'trial']))->operationLabel());
        $this->assertSame('💸 Технический расход / возврат', (new Payment(['tariff' => 'Расход']))->operationLabel());
        $this->assertSame('Весь курс', (new Payment(['tariff' => 'full']))->operationLabel());
    }

    /** @test */
    public function labels_whole_block_and_halves(): void
    {
        $this->assertSame('Блок 3', (new Payment(['tariff' => 'block_3']))->operationLabel());
        $this->assertSame('Блок 3 · 1-я половина', (new Payment(['tariff' => 'block_3_h1']))->operationLabel());
        $this->assertSame('Блок 3 · 2-я половина', (new Payment(['tariff' => 'block_3_h2']))->operationLabel());
    }

    /** @test */
    public function labels_block_range_from_start_end(): void
    {
        $this->assertSame('Блоки 2–5', (new Payment([
            'tariff' => 'block_2', 'start_block' => 2, 'end_block' => 5,
        ]))->operationLabel());

        // Один блок, заданный диапазоном с равными границами.
        $this->assertSame('Блок 2', (new Payment([
            'tariff' => 'block_2', 'start_block' => 2, 'end_block' => 2,
        ]))->operationLabel());
    }
}
