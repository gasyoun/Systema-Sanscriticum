<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H223 D4/D6: вкладки статусов в Helpdesk и индикатор канала ответа.
 */
class HelpdeskTabsTest extends TestCase
{
    use RefreshDatabase;

    private function studentWithChat(string $name): User
    {
        $student = User::factory()->create(['name' => $name]);
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Вопрос '.$name,
            'is_read' => false,
        ]);

        return $student;
    }

    /** @test */
    public function tabs_split_conversations_by_status_and_assignee(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        // inbox: есть сообщение, тред открыт и без ответственного (или треда нет).
        $inbox = $this->studentWithChat('Входящий');

        // mine: тред назначен на текущего куратора.
        $mine = $this->studentWithChat('Мой');
        SupportConversation::create([
            'user_id' => $mine->id,
            'status' => SupportConversation::STATUS_OPEN,
            'assigned_to' => $admin->id,
            'last_message_at' => now(),
        ]);

        // resolved: тред закрыт.
        $resolved = $this->studentWithChat('Решённый');
        SupportConversation::create([
            'user_id' => $resolved->id,
            'status' => SupportConversation::STATUS_CLOSED,
            'last_message_at' => now(),
        ]);

        // Вкладка «Входящие» (по умолчанию): только неназначенный открытый.
        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->assertSee('Входящий')
            ->assertDontSee('Решённый')
            ->call('switchTab', 'mine')
            ->assertSee('Мой')
            ->assertDontSee('Входящий')
            ->call('switchTab', 'resolved')
            ->assertSee('Решённый')
            ->assertDontSee('Мой');
    }

    /** @test */
    public function take_conversation_moves_dialog_into_mine_tab(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = $this->studentWithChat('Ничей');

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->call('takeConversation')
            ->call('switchTab', 'mine')
            ->assertSee('Ничей');

        $this->assertSame(
            $admin->id,
            SupportConversation::where('user_id', $student->id)->value('assigned_to'),
        );
    }

    /** @test */
    public function reply_channel_defaults_to_cabinet(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = $this->studentWithChat('Кабинетный');

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->assertSee('Отвечаю в:')
            ->assertSee('Кабинет');
    }
}
