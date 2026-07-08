<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\FinanceCockpitReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Дымовой рендер страницы «Инвест-решение» (H261, фаза E) + проверка ролей.
 * FinanceCockpitReport подменён дублёром, чтобы defaults() отрисовался на пустой
 * тестовой БД без полного ОПиУ; юнит-экономика читает пустые таблицы штатно.
 */
class InvestmentModelPageSmokeTest extends TestCase
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
                return ['ebitda' => 250_000.0, 'ebitdaMargin' => 22.0, 'revenue' => 1_100_000.0];
            }
        };
        $this->app->instance(FinanceCockpitReport::class, $fake);
    }

    public function test_renders_for_accountant(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $this->actingAs($accountant)->get('/admin/investment-model')->assertSuccessful();
    }

    public function test_owner_admin_can_access(): void
    {
        // RoleGate::finance() = ADMIN ИЛИ ACCOUNTANT (ruling MG H116).
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $this->actingAs($admin)->get('/admin/investment-model')->assertSuccessful();
    }

    public function test_manager_cannot_access(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_admin' => true]);

        $this->actingAs($manager)->get('/admin/investment-model')->assertForbidden();
    }
}
