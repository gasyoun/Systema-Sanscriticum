<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\LeadCost\DirectAdSpendsTableWidget;
use App\Models\DirectAdSpend;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H222 D5 — неизменяемость истории рекламных расходов: менеджер не удаляет,
 * админ удаляет (soft-delete), заблокированные записи защищены от обоих.
 */
class LeadCostSpendImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function spend(bool $locked = false): DirectAdSpend
    {
        return DirectAdSpend::create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now(),
            'specialist_salary' => 10000,
            'ad_budget' => 20000,
            'is_locked' => $locked,
        ]);
    }

    /** @test */
    public function manager_cannot_delete_but_admin_can(): void
    {
        $spend = $this->spend();

        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
        Livewire::test(DirectAdSpendsTableWidget::class)
            ->assertTableActionHidden('delete', $spend);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        Livewire::test(DirectAdSpendsTableWidget::class)
            ->assertTableActionVisible('delete', $spend);
    }

    /** @test */
    public function locked_record_cannot_be_deleted_even_by_admin(): void
    {
        $locked = $this->spend(locked: true);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        Livewire::test(DirectAdSpendsTableWidget::class)
            ->assertTableActionHidden('delete', $locked);
    }

    /** @test */
    public function manager_cannot_edit_locked_record_admin_can(): void
    {
        $locked = $this->spend(locked: true);

        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
        Livewire::test(DirectAdSpendsTableWidget::class)
            ->assertTableActionHidden('edit', $locked);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        Livewire::test(DirectAdSpendsTableWidget::class)
            ->assertTableActionVisible('edit', $locked);
    }

    /** @test */
    public function delete_is_soft_delete_history_survives(): void
    {
        $spend = $this->spend();

        $spend->delete();

        $this->assertSoftDeleted('direct_ad_spends', ['id' => $spend->id]);
        $this->assertNotNull(DirectAdSpend::withTrashed()->find($spend->id));
    }
}
