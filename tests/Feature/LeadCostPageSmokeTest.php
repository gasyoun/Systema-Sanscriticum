<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadCostPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin/lead-cost')->assertSuccessful();
    }
}
