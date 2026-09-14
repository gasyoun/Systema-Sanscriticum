<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Filament\Pages\ActivationCompletionMetrics;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3764 — pin default OFF for activation_completion_metrics. Do not
 * config()->set the flag here: that would test the stub, not the deploy switch.
 */
class ActivationCompletionMetricsFlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_defaults_to_false(): void
    {
        $this->assertFalse((bool) config('features.activation_completion_metrics'));
    }

    public function test_page_is_hidden_when_flag_is_off_for_every_allowed_role(): void
    {
        // Гейт расширен (MG 01-09-2026) до admin/accountant/manager/super_admin —
        // флаг остаётся рубильником для ВСЕХ них, а не только для бухгалтера.
        foreach ([Roles::ACCOUNTANT, Roles::ADMIN, Roles::MANAGER, Roles::SUPER_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));

            $this->assertFalse(ActivationCompletionMetrics::canAccess(), "роль {$role} при флаге OFF");
            $this->assertFalse(ActivationCompletionMetrics::shouldRegisterNavigation(), "меню для {$role} при флаге OFF");
        }
    }

    public function test_route_is_forbidden_when_flag_is_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $this->get('/admin/activation-completion-metrics')->assertForbidden();
    }
}
