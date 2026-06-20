<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Debtors;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Глобальное запоминание выбранного года в «Должниках»
 * (MarketingSetting.debtors_notify_years) + чтение его DebtorsReport.
 */
class DebtorsYearPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    /** @test */
    public function toggling_year_persists_to_marketing_setting(): void
    {
        Livewire::actingAs($this->admin())->test(Debtors::class)
            ->assertSet('selectedYears', [])
            ->call('toggleYear', 2026)
            ->assertSet('selectedYears', [2026]);

        $this->assertSame([2026], MarketingSetting::first()->debtors_notify_years);

        // Добавление второго года — оба сохраняются отсортированными.
        Livewire::actingAs($this->admin())->test(Debtors::class)
            ->call('toggleYear', 2025)
            ->assertSet('selectedYears', [2025, 2026]);

        $this->assertSame([2025, 2026], MarketingSetting::first()->debtors_notify_years);
    }

    /** @test */
    public function page_restores_year_from_setting_on_mount(): void
    {
        MarketingSetting::create(['debtors_notify_years' => [2025]]);

        Livewire::actingAs($this->admin())->test(Debtors::class)
            ->assertSet('selectedYears', [2025]);
    }

    /** @test */
    public function deselecting_persists_empty_all_years(): void
    {
        MarketingSetting::create(['debtors_notify_years' => [2026]]);

        Livewire::actingAs($this->admin())->test(Debtors::class)
            ->assertSet('selectedYears', [2026])
            ->call('toggleYear', 2026)
            ->assertSet('selectedYears', []);

        $this->assertSame([], MarketingSetting::first()->debtors_notify_years);
    }

    /** @test */
    public function configured_years_reads_normalized_setting(): void
    {
        // Грязные данные в настройке нормализуются (int, уникальные, >0, сортировка).
        MarketingSetting::create(['debtors_notify_years' => ['2026', 2025, 0, 2025]]);
        MarketingSetting::flushCached();

        $this->assertSame([2025, 2026], DebtorsReport::configuredYears());
    }

    /** @test */
    public function configured_years_empty_when_no_setting(): void
    {
        $this->assertSame([], DebtorsReport::configuredYears());
    }
}
