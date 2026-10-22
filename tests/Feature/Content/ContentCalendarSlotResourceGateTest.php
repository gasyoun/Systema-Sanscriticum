<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Filament\Resources\ContentCalendarSlotResource;
use App\Models\ContentCalendarSlot;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ContentCalendarSlotResourceGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_hidden_when_flag_off_even_for_admin(): void
    {
        config(['features.content_calendar' => false]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertFalse(ContentCalendarSlotResource::canViewAny());
        $this->assertFalse(ContentCalendarSlotResource::shouldRegisterNavigation());
    }

    public function test_resource_hidden_for_non_admin_even_with_flag_on(): void
    {
        config(['features.content_calendar' => true]);
        $student = User::factory()->create();
        $this->actingAs($student);

        $this->assertFalse(ContentCalendarSlotResource::canViewAny());
    }

    public function test_resource_visible_for_admin_with_flag_on(): void
    {
        config(['features.content_calendar' => true]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $this->assertTrue(ContentCalendarSlotResource::canViewAny());
        $this->assertTrue(ContentCalendarSlotResource::shouldRegisterNavigation());
    }

    /** Filament smoke proxy (D19): list page renders for admin when flag ON. */
    public function test_list_page_renders_for_admin_when_flag_on(): void
    {
        config(['features.content_calendar' => true]);
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $this->actingAs($admin);

        ContentCalendarSlot::create([
            'slot_date' => '2026-09-01',
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_DRAFT,
            'title' => 'Smoke',
        ]);

        Livewire::test(ContentCalendarSlotResource\Pages\ListContentCalendarSlots::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(ContentCalendarSlot::all());
    }

    public function test_edit_page_attaches_video_to_scheduled_slot(): void
    {
        config(['features.content_calendar' => true]);
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $this->actingAs($admin);

        $slot = ContentCalendarSlot::create([
            'slot_date' => '2026-09-07',
            'slot_type' => ContentCalendarSlot::TYPE_TEACHER_SPOTLIGHT,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => '2026-09-07 12:00:00',
            'title' => 'Спотлайт',
            'body' => 'Текст',
        ]);

        Livewire::test(ContentCalendarSlotResource\Pages\EditContentCalendarSlot::class, ['record' => $slot->getRouteKey()])
            ->assertSuccessful()
            ->set('data.media_path', UploadedFile::fake()->create('teaser.mp4', 2048, 'video/mp4'))
            ->call('save')
            ->assertHasNoFormErrors();

        $slot->refresh();
        $this->assertStringStartsWith('content-calendar/', (string) $slot->media_path);
        Storage::disk(ContentCalendarSlot::MEDIA_DISK)->assertExists($slot->media_path);
        $this->assertSame(ContentCalendarSlot::MEDIA_VIDEO, $slot->mediaType());
    }

    private function crosspostSlot(): ContentCalendarSlot
    {
        config([
            'features.content_calendar' => true,
            'content_calendar.destinations' => [
                'tg_a' => ['platform' => 'telegram', 'label' => 'TG A', 'chat_id' => '@a'],
                'tg_off' => ['platform' => 'telegram', 'label' => 'TG off', 'chat_id' => ''],
                'vk_a' => ['platform' => 'vk', 'label' => 'VK A', 'group_id' => 111],
            ],
        ]);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        return ContentCalendarSlot::create([
            'slot_date' => '2026-09-07',
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => '2026-09-07 12:00:00',
            'body' => 'Старый простой текст',
        ]);
    }

    public function test_edit_saves_formatted_body_and_chosen_destinations(): void
    {
        $slot = $this->crosspostSlot();

        Livewire::test(ContentCalendarSlotResource\Pages\EditContentCalendarSlot::class, ['record' => $slot->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet(['destinations' => ['tg_a', 'vk_a']])
            ->assertSee('Старый простой текст')
            ->set('data.body', '<p><strong>Ом</strong> <mark>тайна</mark></p>')
            ->set('data.destinations', ['tg_a'])
            ->call('save')
            ->assertHasNoFormErrors();

        $slot->refresh();
        $this->assertSame('<p><strong>Ом</strong> <mark>тайна</mark></p>', $slot->body);
        $this->assertSame(['tg_a'], $slot->destinations);
        $this->assertSame(['tg_a'], $slot->targetDestinations());
    }

    public function test_caption_over_1024_with_media_is_rejected(): void
    {
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        $slot = $this->crosspostSlot();
        $long = '<p>'.str_repeat('а', 1025).'</p>';

        Livewire::test(ContentCalendarSlotResource\Pages\EditContentCalendarSlot::class, ['record' => $slot->getRouteKey()])
            ->set('data.media_path', UploadedFile::fake()->image('cover.jpg'))
            ->set('data.body', $long)
            ->call('save')
            ->assertHasFormErrors(['body']);

        Livewire::test(ContentCalendarSlotResource\Pages\EditContentCalendarSlot::class, ['record' => $slot->getRouteKey()])
            ->set('data.body', $long)
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
