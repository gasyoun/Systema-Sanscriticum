<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HelpdeskUnifiedInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_helpdesk_renders_both_channels_in_one_stream(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();

        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Вопрос из кабинета',
            'is_read' => false,
        ]);

        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9001,
            'linked_user_id' => $student->id,
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9001,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Вопрос из телеграма',
            'sent_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->assertSee('Вопрос из кабинета')
            ->assertSee('Вопрос из телеграма')
            ->assertSee('Кабинет')
            ->assertSee('Telegram');
    }

    public function test_telegram_only_user_appears_in_sidebar(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $tgOnly = User::factory()->create(['name' => 'Телеграм Онли']);

        TelegramSupportChat::create([
            'telegram_chat_id' => 9002,
            'linked_user_id' => $tgOnly->id,
        ]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->assertSee('Телеграм Онли');
    }

    /** H1200: VK/TG-bot messages get their own distinct channel badges, not lumped into "Кабинет". */
    public function test_helpdesk_badges_vk_and_telegram_bot_as_distinct_channels(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();

        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Вопрос из ВК-бота',
            'is_read' => false,
            'source' => 'vk',
        ]);
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Вопрос из TG-бота кабинета',
            'is_read' => false,
            'source' => 'telegram_bot',
        ]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->assertSee('Вопрос из ВК-бота')
            ->assertSee('Вопрос из TG-бота кабинета')
            ->assertSee('ВКонтакте')
            ->assertSee('Telegram-бот')
            ->assertSee('msg-channel vk', false)
            ->assertSee('msg-channel telegram_bot', false);
    }
}
