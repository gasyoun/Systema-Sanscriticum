<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Filament\Resources\ContentCalendarSlotResource;
use App\Models\ContentCalendarSlot;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Клик по превью обложки открывает зум-модалку поверх страницы (29-10-2026). */
class ContentCalendarSlotCoverZoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_zoom_action_mounts_for_photo_slot(): void
    {
        config(['features.content_calendar' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $slot = ContentCalendarSlot::create([
            'slot_date' => '2026-09-07',
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_DRAFT,
            'title' => 'Ловушка · тест',
            'media_path' => 'content-calendar/cover.png',
        ]);

        Livewire::test(ContentCalendarSlotResource\Pages\ListContentCalendarSlots::class)
            ->assertSuccessful()
            ->assertTableActionVisible('zoom_media_preview', $slot)
            ->callTableAction('zoom_media_preview', $slot)
            ->assertSuccessful();
    }

    public function test_zoom_action_hidden_without_media(): void
    {
        config(['features.content_calendar' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $slot = ContentCalendarSlot::create([
            'slot_date' => '2026-09-07',
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_DRAFT,
            'title' => 'Без медиа',
        ]);

        Livewire::test(ContentCalendarSlotResource\Pages\ListContentCalendarSlots::class)
            ->assertSuccessful()
            ->assertTableActionHidden('zoom_media_preview', $slot);
    }
}
