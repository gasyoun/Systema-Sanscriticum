<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Helpdesk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CuratorBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_curator_reply_saves_responder_and_signs_message_with_alias(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $curator = User::factory()->create(['is_admin' => true, 'curator_display_name' => 'Маша']);
        $student = User::factory()->create(['telegram_id' => 555000111]);
        Cache::put("chat_human_{$student->telegram_id}", true, 7200);

        Livewire::actingAs($curator)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->set('newMessage', 'Здравствуйте, помогу с оплатой')
            ->call('sendMessageToStudent');

        // Кто ответил — зафиксировано.
        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $student->id,
            'role' => 'curator',
            'answered_by' => $curator->id,
            'text' => 'Здравствуйте, помогу с оплатой',
        ]);

        // Студенту ушёл бэйдж с псевдонимом.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($request->data()['text'] ?? '', '👨‍🏫 <b>Маша</b>:');
        });
    }

    public function test_display_name_falls_back_to_real_name_when_alias_empty(): void
    {
        $u = User::factory()->create(['name' => 'Иван Иванов', 'curator_display_name' => null]);

        $this->assertSame('Иван Иванов', $u->curatorDisplayName());

        $u->curator_display_name = 'куратор Маша';
        $this->assertSame('куратор Маша', $u->curatorDisplayName());
    }
}
