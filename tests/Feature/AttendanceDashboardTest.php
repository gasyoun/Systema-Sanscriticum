<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AttendanceDashboard;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function page_is_hidden_when_feature_flag_is_off(): void
    {
        config(['features.attendance_dashboard' => false]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertFalse(AttendanceDashboard::canAccess());
    }

    /** @test */
    public function page_is_hidden_for_non_admin_non_teacher_even_with_flag_on(): void
    {
        config(['features.attendance_dashboard' => true]);
        $student = User::factory()->create();
        $this->actingAs($student);

        $this->assertFalse(AttendanceDashboard::canAccess());
    }

    /** @test */
    public function page_renders_report_for_admin_when_flag_is_on(): void
    {
        config(['features.attendance_dashboard' => true]);

        $group = Group::create(['name' => 'Группа Д1']);
        $student = User::factory()->create(['name' => 'Индира']);
        $group->users()->attach($student->id);

        $schedule = Schedule::create(['title' => 'Занятие', 'start' => now()->subDay(), 'group_id' => $group->id]);
        WebinarAttendance::create([
            'schedule_id' => $schedule->id, 'user_id' => $student->id,
            'zoom_participant_uuid' => 'd1', 'joined_at' => now()->subDay(), 'duration_seconds' => 1800,
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $this->assertTrue(AttendanceDashboard::canAccess());

        Livewire::test(AttendanceDashboard::class)
            ->assertOk()
            ->assertSee('Индира');
    }
}
