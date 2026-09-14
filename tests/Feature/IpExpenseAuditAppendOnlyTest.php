<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IpExpense;
use App\Models\IpExpenseAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Append-only + аудит контура «Расходы ИП» (H4188 п.1, конвенции
 * payment_audits): удаление строки запрещено, правки пишутся диффом,
 * автор-человек снимком имени, CLI-действия — «Система».
 */
class IpExpenseAuditAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function expense_row_cannot_be_deleted(): void
    {
        $expense = IpExpense::query()->create([
            'spent_at' => '2026-07-01',
            'payee' => 'Хостер',
            'amount' => '500.00',
            'category' => 'contractors',
            'source_tab' => 'Июль 2026',
            'import_hash' => 'test-hash-1',
        ]);

        $this->expectException(\RuntimeException::class);
        $expense->delete();
    }

    /** @test */
    public function update_writes_audited_diff_with_system_author(): void
    {
        $expense = IpExpense::query()->create([
            'spent_at' => '2026-07-01',
            'payee' => 'Хостер',
            'amount' => '500.00',
            'category' => 'contractors',
            'source_tab' => 'Июль 2026',
            'import_hash' => 'test-hash-2',
        ]);

        $this->actingAs(User::factory()->create())
            ->withoutExceptionHandling();

        $expense->update(['amount' => '650.50', 'category' => 'other']);

        $audit = IpExpenseAudit::query()
            ->where('action', IpExpenseAudit::ACTION_UPDATED)
            ->where('ip_expense_id', $expense->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('650.50', $audit->amount);
        $this->assertEquals(['500.00', '650.50'], $audit->changes['amount']);
        $this->assertEquals(['contractors', 'other'], $audit->changes['category']);
        $this->assertNotNull($audit->admin_id);
    }

    /** @test */
    public function cli_creation_is_audited_as_system(): void
    {
        $expense = IpExpense::query()->create([
            'spent_at' => null,
            'payee' => 'Фрахт',
            'amount' => '2000.00',
            'category' => 'other',
            'source_tab' => 'Ноябрь 2025',
            'import_hash' => 'test-hash-3',
        ]);

        $audit = IpExpenseAudit::query()
            ->where('action', IpExpenseAudit::ACTION_CREATED)
            ->where('ip_expense_id', $expense->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Система', $audit->admin_name);
        $this->assertNull($audit->admin_id);
        $this->assertSame('Ноябрь 2025', $audit->changes['source_tab']);
    }
}
