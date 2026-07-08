<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\FinanceCockpitReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Дымовой рендер страниц фазы D (H259): «Фонды прибыли» и «KPI делегирования».
 * FinanceCockpitReport подменён дублёром — чтобы страница отрисовалась на пустой
 * тестовой БД без зависимости от полного ОПиУ. Проверяем доступ по ролям и что
 * вёрстка (blade) не падает.
 */
class ProfitFundsPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $fake = new class extends FinanceCockpitReport
        {
            public function __construct() {}

            public function opiuAccrual(string $period): array
            {
                return ['ebitda' => 300_000.0, 'ebitdaMargin' => 25.0, 'revenue' => 1_200_000.0];
            }

            public function dds(string $period): array
            {
                return ['net' => 300_000.0];
            }

            public function deferredRevenue(string $period): array
            {
                return ['cashReceived' => 0.0, 'recognized' => 0.0, 'deferred' => 0.0];
            }
        };
        $this->app->instance(FinanceCockpitReport::class, $fake);
    }

    public function test_profit_funds_renders_for_accountant(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $this->actingAs($accountant)->get('/admin/profit-funds')->assertSuccessful();
    }

    public function test_delegation_kpi_renders_for_accountant(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $this->actingAs($accountant)->get('/admin/delegation-kpi')->assertSuccessful();
    }

    public function test_owner_admin_can_access_profit_funds(): void
    {
        // RoleGate::finance() = ADMIN ИЛИ ACCOUNTANT: владелец-админ тоже видит
        // управленческий финконтур (ruling MG H116).
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $this->actingAs($admin)->get('/admin/profit-funds')->assertSuccessful();
    }

    public function test_manager_cannot_access_delegation_kpi(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_admin' => true]);

        $this->actingAs($manager)->get('/admin/delegation-kpi')->assertForbidden();
    }

    public function test_kpi_digest_command_runs_dry(): void
    {
        // Команда зарегистрирована и собирает сводку без падения; --dry не шлёт.
        $this->artisan('finance:kpi-digest', ['--dry' => true])->assertSuccessful();
    }
}
