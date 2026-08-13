<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\Customer360;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2483 — пиннинг дефолта `crm_customer_360`. Ничего не подставляем:
 * иначе тест проверял бы собственную подстановку, а не прод-рубильник.
 */
class Customer360FlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_customer_360_defaults_to_false(): void
    {
        $this->assertFalse((bool) config('features.crm_customer_360'));
    }

    public function test_workspace_is_hidden_when_flag_is_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $this->assertFalse(Customer360::canAccess());
        $this->assertFalse(Customer360::shouldRegisterNavigation());
    }
}
