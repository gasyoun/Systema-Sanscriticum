<?php

declare(strict_types=1);

namespace Tests\Feature\LectureClips;

use App\Filament\Resources\LectureClipResource;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LectureClipResourceGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_hidden_when_flag_off_even_for_admin(): void
    {
        config(['features.clip_marketing' => false]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertFalse(LectureClipResource::canViewAny());
        $this->assertFalse(LectureClipResource::shouldRegisterNavigation());
    }

    public function test_resource_hidden_for_non_admin_even_with_flag_on(): void
    {
        config(['features.clip_marketing' => true]);
        $student = User::factory()->create();
        $this->actingAs($student);

        $this->assertFalse(LectureClipResource::canViewAny());
    }

    public function test_resource_visible_for_admin_with_flag_on(): void
    {
        config(['features.clip_marketing' => true]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertTrue(LectureClipResource::canViewAny());
        $this->assertTrue(LectureClipResource::shouldRegisterNavigation());
    }
}
