<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\CourseEarningsChart;
use App\Filament\Widgets\DebtorsTotalWidget;
use App\Filament\Widgets\StudentStatsOverview;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4663 (аудит периметра 14-09, п.3): дашборд-виджеты с деньгами под гейтом.
 * Выручка/LTV/школьный долг — управленческие цифры: finance() для выручки,
 * админ+куратор для суммы долгов. Кейс-регресс на «единственные без canView».
 */
class DashboardWidgetRoleGateTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @test */
    public function revenue_widgets_are_finance_only(): void
    {
        foreach ([CourseEarningsChart::class, StudentStatsOverview::class] as $widget) {
            $this->actingAs($this->user(Roles::SUPER_ADMIN));
            $this->assertTrue($widget::canView(), "super_admin: $widget");

            $this->actingAs($this->user(Roles::ACCOUNTANT));
            $this->assertTrue($widget::canView(), "accountant: $widget");

            $this->actingAs($this->user(Roles::MANAGER));
            $this->assertFalse($widget::canView(), "manager denied: $widget");

            $this->actingAs($this->user(Roles::TEACHER));
            $this->assertFalse($widget::canView(), "teacher denied: $widget");

            $this->actingAs($this->user(null));
            $this->assertFalse($widget::canView(), "student denied: $widget");
        }
    }

    /** @test */
    public function debtors_total_widget_matches_debtors_page_gate(): void
    {
        $this->actingAs($this->user(Roles::SUPER_ADMIN));
        $this->assertTrue(DebtorsTotalWidget::canView());

        $this->actingAs($this->user(Roles::MANAGER));
        $this->assertTrue(DebtorsTotalWidget::canView(), 'куратор работает с должниками (рулинг MG 07-09)');

        $this->actingAs($this->user(Roles::ACCOUNTANT));
        $this->assertFalse(DebtorsTotalWidget::canView());

        $this->actingAs($this->user(Roles::TEACHER));
        $this->assertFalse(DebtorsTotalWidget::canView());

        $this->actingAs($this->user(null));
        $this->assertFalse(DebtorsTotalWidget::canView());
    }
}
