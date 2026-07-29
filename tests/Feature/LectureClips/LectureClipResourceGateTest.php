<?php

declare(strict_types=1);

namespace Tests\Feature\LectureClips;

use App\Filament\Resources\LectureClipResource;
use App\Filament\Resources\LectureClipResource\Pages\ListLectureClips;
use App\Models\Course;
use App\Models\LectureClip;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    /**
     * Регрессия 29.07.2026: страница списка падала 500-й на первом же клипе —
     * `->boolean(getStateUsing: ...)` передавал IconColumn::boolean() именованный
     * параметр, которого у метода нет (Unknown named parameter $getStateUsing).
     * Гейт-тесты выше это не ловили: таблица не рендерилась ни разу.
     */
    public function test_list_page_renders_table_with_clips(): void
    {
        config(['features.clip_marketing' => true]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $lesson = Lesson::factory()->for(Course::factory())->create();

        $inVk = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Клип в VK',
            'vk_video_id' => '456239017',
            'vk_owner_id' => '-12345',
        ]);
        $notInVk = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 90,
            'end_seconds' => 180,
            'title' => 'Клип без VK',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListLectureClips::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$inVk, $notInVk])
            ->assertSee('Клип в VK')
            ->assertSee('Клип без VK');
    }
}
