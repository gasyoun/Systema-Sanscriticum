<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LessonResource;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function teacher_is_redirected_from_dashboard_not_forbidden(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 't@example.test']);
        $user = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Раньше тут было 403 — теперь должен быть редирект на раздел преподавателя.
        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(LessonResource::getUrl());
    }

    /** @test */
    public function admin_still_sees_dashboard(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($admin)
            ->get('/admin')
            ->assertSuccessful();
    }

    /** @test */
    public function teacher_landing_resource_opens_without_redirect_loop(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 't@example.test']);
        $user = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user)
            ->get(LessonResource::getUrl())
            ->assertSuccessful();
    }
}
