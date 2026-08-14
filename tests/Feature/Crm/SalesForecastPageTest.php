<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\SalesForecast;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesForecastPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.crm_sales_forecast' => true]);
    }

    public function test_admin_sees_all_managers_and_config_assumptions(): void
    {
        $anna = User::factory()->create(['name' => 'Анна', 'role' => Roles::MANAGER]);
        $boris = User::factory()->create(['name' => 'Борис', 'role' => Roles::MANAGER]);
        $new = DealStage::query()->where('key', 'new')->firstOrFail();
        Deal::factory()->create(['amount' => 10000, 'stage_id' => $new->id, 'assigned_to' => $anna->id]);
        Deal::factory()->create(['amount' => 20000, 'stage_id' => $new->id, 'assigned_to' => $boris->id]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertTrue(SalesForecast::canAccess());
        $this->get('/admin/sales-forecast')
            ->assertSuccessful()
            ->assertSee('Допущения')
            ->assertSee('config/crm_forecast.php')
            ->assertSee('Анна')
            ->assertSee('Борис')
            ->assertSee('Открытый пайплайн')
            ->assertSee('нет данных', false);
    }

    public function test_manager_does_not_see_other_manager_pipeline_rows(): void
    {
        $anna = User::factory()->create(['name' => 'Анна', 'role' => Roles::MANAGER]);
        $boris = User::factory()->create(['name' => 'Борис', 'role' => Roles::MANAGER]);
        $new = DealStage::query()->where('key', 'new')->firstOrFail();
        Deal::factory()->create(['amount' => 10000, 'stage_id' => $new->id, 'assigned_to' => $anna->id]);
        Deal::factory()->create(['amount' => 20000, 'stage_id' => $new->id, 'assigned_to' => $boris->id]);

        $this->actingAs($anna);
        $this->assertTrue(SalesForecast::canAccess());

        $html = $this->get('/admin/sales-forecast')->assertSuccessful()->getContent();
        $this->assertStringContainsString('deals.assigned_to', $html);
        $this->assertStringContainsString('ваши сделки', $html);
        $this->assertStringNotContainsString('Борис', $html);
    }

    public function test_teacher_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(SalesForecast::canAccess());
        $this->get('/admin/sales-forecast')->assertForbidden();
    }

    public function test_blade_does_not_hardcode_stage_probabilities(): void
    {
        $blade = file_get_contents(resource_path('views/filament/pages/sales-forecast.blade.php'));
        $this->assertIsString($blade);
        $this->assertStringNotContainsString('0.15', $blade);
        $this->assertStringNotContainsString('0.35', $blade);
        $this->assertStringNotContainsString('0.60', $blade);
        $this->assertStringContainsString('stage_probabilities', $blade);
    }

    public function test_accountant_can_access_like_manager_sales_gate(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));
        $this->assertTrue(SalesForecast::canAccess());
        $this->get('/admin/sales-forecast')->assertSuccessful();
    }
}
