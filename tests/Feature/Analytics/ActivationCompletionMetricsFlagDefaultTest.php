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

    public function test_page_is_hidden_when_flag_is_off_even_for_accountant(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $this->assertFalse(ActivationCompletionMetrics::canAccess());
        $this->assertFalse(ActivationCompletionMetrics::shouldRegisterNavigation());
    }

    public function test_route_is_forbidden_when_flag_is_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $this->get('/admin/activation-completion-metrics')->assertForbidden();
    }
}
