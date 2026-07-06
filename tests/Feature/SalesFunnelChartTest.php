<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\SalesFunnelChart;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H223 stretch: воронка продаж снова видна менеджеру/админу и показывает
 * конверсию «лид → клиент» в заголовке.
 */
class SalesFunnelChartTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function widget_is_visible_to_manager_but_not_to_teacher(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
        $this->assertTrue(SalesFunnelChart::canView());

        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(SalesFunnelChart::canView());
    }

    /** @test */
    public function heading_reports_zero_conversion_without_leads(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $this->assertSame('Воронка продаж (конверсия 0%)', (new SalesFunnelChart)->getHeading());
    }
}
