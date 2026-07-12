<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function guest_sees_the_login_form(): void
    {
        $this->get('/login')->assertOk();
    }

    /** @test */
    public function authenticated_student_is_redirected_to_dvaram(): void
    {
        $student = User::factory()->create(['is_admin' => false]);

        $this->actingAs($student)
            ->get('/login')
            ->assertRedirect(route('student.dashboard'));
    }

    /** @test */
    public function authenticated_admin_is_redirected_to_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/login')
            ->assertRedirect('/admin');
    }
}
