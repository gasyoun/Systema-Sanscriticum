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
 * Полный механизм назначения ответственного за тред поддержки (H221 D4) —
 * селектор в шапке Helpdesk (assign/reassign любому куратору), дополняющий
 * «Взять диалог себе» из H230. Интегрируется с вкладкой «Мои» (H230).
 */
class HelpdeskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Рендер Filament-страницы шифрует данные Livewire — нужен ключ приложения.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
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
    public function assign_thread_sets_assigned_to_chosen_curator(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $curator = User::factory()->create(['role' => Roles::MANAGER]);
        $student = $this->studentWithChat();
        $this->actingAs($admin);

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('assignThread', $curator->id);

        $this->assertDatabaseHas('support_conversations', [
            'user_id' => $student->id,
            'assigned_to' => $curator->id,
        ]);
    }

    /** @test */
    public function assign_thread_with_empty_value_clears_responsible(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = $this->studentWithChat();
        SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
            'assigned_to' => $admin->id,
            'last_message_at' => now(),
        ]);
        $this->actingAs($admin);

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('assignThread', '');

        $this->assertDatabaseHas('support_conversations', [
            'user_id' => $student->id,
            'assigned_to' => null,
        ]);
    }

    /** @test */
    public function assigned_thread_appears_under_mine_tab(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $mine = $this->studentWithChat();
        $other = $this->studentWithChat();
        $this->actingAs($admin);

        // Назначаю тред себе через полный механизм H221, затем смотрю вкладку «Мои» (H230).
        $component = Livewire::test(Helpdesk::class)
            ->set('activeUserId', $mine->id)
            ->call('assignThread', $admin->id)
            ->call('switchTab', 'mine');

        $ids = collect($component->get('usersWithChats'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** @test */
    public function curators_list_contains_admins_and_managers_only(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $student = User::factory()->create(['role' => null]);
        $this->actingAs($admin);

        $page = new Helpdesk;
        $curators = $page->getCuratorsProperty();

        $this->assertArrayHasKey($admin->id, $curators);
        $this->assertArrayHasKey($manager->id, $curators);
        $this->assertArrayNotHasKey($teacher->id, $curators);
        $this->assertArrayNotHasKey($student->id, $curators);
    }
}
