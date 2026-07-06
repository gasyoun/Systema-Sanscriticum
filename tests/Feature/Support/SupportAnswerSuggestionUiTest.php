<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupportAnswerSuggestionUiTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDraft(User $student): SupportAnswerSuggestion
    {
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Есть ссылка на занятие?',
            'is_read' => false,
        ]);

        return SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'detected_text' => 'Есть ссылка на занятие?',
            'draft_text' => 'Ближайшее занятие завтра. Ссылка: https://zoom.us/j/777',
            'facts' => ['type' => 'zoom'],
            'confidence' => 0.9,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_helpdesk_banner_shows_draft_and_accept_fills_reply(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        $suggestion = $this->pendingDraft($student);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->assertSee('https://zoom.us/j/777')
            ->call('acceptAnswerSuggestion', $suggestion->id)
            ->assertSet('newMessage', 'Ближайшее занятие завтра. Ссылка: https://zoom.us/j/777');

        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $suggestion->fresh()->status);
    }

    public function test_helpdesk_discard_marks_discarded(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        $suggestion = $this->pendingDraft($student);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->call('discardAnswerSuggestion', $suggestion->id);

        $this->assertSame(SupportAnswerSuggestion::STATUS_DISCARDED, $suggestion->fresh()->status);
    }
}
