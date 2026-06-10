<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\LessonMaterials;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Страница «Материалы уроков» доступна только суперадмину.
 */
class LessonMaterialsAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function only_super_admin_passes_the_access_gate(): void
    {
        $super = User::factory()->create(['role' => Roles::SUPER_ADMIN]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create(['role' => null]);

        $this->actingAs($super);
        $this->assertTrue(LessonMaterials::canAccess());

        $this->actingAs($admin);
        $this->assertFalse(LessonMaterials::canAccess());

        $this->actingAs($student);
        $this->assertFalse(LessonMaterials::canAccess());
    }

    /** @test */
    public function super_admin_can_open_the_page(): void
    {
        $super = User::factory()->create(['role' => Roles::SUPER_ADMIN]);

        $this->actingAs($super)
            ->get('/admin/lesson-materials')
            ->assertOk();
    }
}
