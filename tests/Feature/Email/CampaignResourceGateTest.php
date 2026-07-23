<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Filament\Resources\CampaignResource;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1449 B8 — CampaignResource is gated on BOTH the `email_campaigns` flag and
 * an admin role; the flag OFF must hide it even for an admin.
 */
class CampaignResourceGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_is_hidden_when_flag_is_off_even_for_admin(): void
    {
        config(['features.email_campaigns' => false]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertFalse(CampaignResource::shouldRegisterNavigation());
        $this->assertFalse(CampaignResource::canViewAny());
    }

    public function test_resource_is_hidden_for_non_admin_even_with_flag_on(): void
    {
        config(['features.email_campaigns' => true]);
        $student = User::factory()->create();
        $this->actingAs($student);

        $this->assertFalse(CampaignResource::canViewAny());
    }

    public function test_resource_is_visible_for_admin_with_flag_on(): void
    {
        config(['features.email_campaigns' => true]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertTrue(CampaignResource::shouldRegisterNavigation());
        $this->assertTrue(CampaignResource::canViewAny());
    }
}
