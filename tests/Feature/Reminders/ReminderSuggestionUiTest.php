<?php

declare(strict_types=1);

namespace Tests\Feature\Reminders;

use App\Filament\Pages\Helpdesk;
use App\Filament\Resources\ReminderSuggestionResource\Pages\ListReminderSuggestions;
use App\Models\ChatMessage;
use App\Models\ReminderSuggestion;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReminderSuggestionUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_queue_lists_pending_suggestion(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        $suggestion = ReminderSuggestion::create([
            'user_id' => $student->id,
            'source_type' => ReminderSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'detected_text' => 'Напомнить про оплату',
            'confidence' => 0.8,
            'status' => ReminderSuggestion::STATUS_PENDING,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListReminderSuggestions::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$suggestion]);
    }

    public function test_helpdesk_banner_shows_pending_suggestion_and_approve_creates_reminder(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Напомните мне после 12-го',
            'is_read' => false,
        ]);
        $suggestion = ReminderSuggestion::create([
            'user_id' => $student->id,
            'source_type' => ReminderSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'detected_text' => 'Напомнить про отзыв о курсе',
            'suggested_date' => now()->addDays(5),
            'confidence' => 0.8,
            'status' => ReminderSuggestion::STATUS_PENDING,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->assertSee('Напомнить про отзыв о курсе')
            ->call('approveReminderSuggestion', $suggestion->id);

        $this->assertDatabaseCount('scheduled_reminders', 1);
        $this->assertSame(ReminderSuggestion::STATUS_APPROVED, $suggestion->fresh()->status);
    }

    public function test_helpdesk_dismiss_does_not_create_reminder(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        $suggestion = ReminderSuggestion::create([
            'user_id' => $student->id,
            'source_type' => ReminderSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 2,
            'detected_text' => 'Напомнить про что-то',
            'confidence' => 0.6,
            'status' => ReminderSuggestion::STATUS_PENDING,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->call('dismissReminderSuggestion', $suggestion->id);

        $this->assertSame(ReminderSuggestion::STATUS_DISMISSED, $suggestion->fresh()->status);
        $this->assertDatabaseCount('scheduled_reminders', 0);
    }
}
