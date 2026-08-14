<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\SalesForecast;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2485 — pin default OFF for crm_sales_forecast. Do not config()->set the
 * flag here: that would test the stub, not the deploy switch.
 */
class SalesForecastFlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_sales_forecast_defaults_to_false(): void
    {
        $this->assertFalse((bool) config('features.crm_sales_forecast'));
    }

    public function test_page_is_hidden_when_flag_is_off_even_for_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $this->assertFalse(SalesForecast::canAccess());
        $this->assertFalse(SalesForecast::shouldRegisterNavigation());
    }
}
