<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Назначение ответственного за тред поддержки и фильтр «мои диалоги» (H221 D4).
 */
class HelpdeskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.crm_cockpit' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function studentWithChat(): User
    {
        $student = User::factory()->create(['role' => null]);
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'вопрос',
            'is_read' => false,
        ]);

        return $student;
    }

    /** @test */
    public function assign_thread_sets_assigned_to(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = $this->studentWithChat();
        $this->actingAs($admin);

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('assignThread', $admin->id);

        $this->assertDatabaseHas('support_conversations', [
            'user_id' => $student->id,
            'assigned_to' => $admin->id,
        ]);
    }

    /** @test */
    public function mine_filter_shows_only_threads_assigned_to_me(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $mine = $this->studentWithChat();
        $other = $this->studentWithChat();
        $this->actingAs($admin);

        SupportConversation::create([
            'user_id' => $mine->id,
            'status' => SupportConversation::STATUS_OPEN,
            'assigned_to' => $admin->id,
            'last_message_at' => now(),
        ]);
        SupportConversation::create([
            'user_id' => $other->id,
            'status' => SupportConversation::STATUS_OPEN,
            'assigned_to' => null,
            'last_message_at' => now(),
        ]);

        $component = Livewire::test(Helpdesk::class)->call('setAssignmentFilter', 'mine');

        $ids = collect($component->get('usersWithChats'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** @test */
    public function flag_off_ignores_assignment_filter(): void
    {
        config(['features.crm_cockpit' => false]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $mine = $this->studentWithChat();
        $other = $this->studentWithChat();
        $this->actingAs($admin);

        SupportConversation::create([
            'user_id' => $mine->id,
            'status' => SupportConversation::STATUS_OPEN,
            'assigned_to' => $admin->id,
            'last_message_at' => now(),
        ]);

        // Даже при фильтре 'mine' без флага список не сужается.
        $component = Livewire::test(Helpdesk::class)
            ->set('assignmentFilter', 'mine')
            ->call('loadUsersList');

        $ids = collect($component->get('usersWithChats'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertContains($other->id, $ids);
    }
}
