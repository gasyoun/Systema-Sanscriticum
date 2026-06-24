<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessStudentChatReply;
use App\Livewire\StudentChat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\StudentChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CabinetWebChatTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function sending_saves_message_instantly_and_queues_ai_reply(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StudentChat::class)
            ->set('newMessage', 'Когда начинается курс?')
            ->call('send')
            ->assertSet('newMessage', '');

        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Когда начинается курс?',
            'is_read' => false,
        ]);
        Queue::assertPushed(ProcessStudentChatReply::class, fn ($job) => $job->userId === $user->id);
    }

    /** @test */
    public function respond_saves_ai_answer(): void
    {
        config(['services.openrouter.api_key' => 'test-key', 'services.openrouter.model' => 'test-model']);
        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Сандхи — это…']]]]),
        ]);

        $user = User::factory()->create();
        app(StudentChatService::class)->respond($user, 'Объясните правило сандхи');

        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $user->id,
            'role' => 'bot',
            'text' => 'Сандхи — это…',
        ]);
    }

    /** @test */
    public function calling_curator_enables_human_mode_and_silences_ai(): void
    {
        Http::fake(); // если ИИ позовётся — тест поймает лишний бот-ответ
        $user = User::factory()->create();
        $service = app(StudentChatService::class);

        $service->respond($user, 'позови куратора, пожалуйста');

        // Бот подтвердил передачу куратору.
        $this->assertSame(1, ChatMessage::where('user_id', $user->id)->where('role', 'bot')->count());

        // В режиме человека следующий вопрос ИИ не отвечает — новых бот-сообщений нет.
        $service->respond($user, 'у меня ещё один вопрос');
        $this->assertSame(1, ChatMessage::where('user_id', $user->id)->where('role', 'bot')->count());
    }

    /** @test */
    public function chat_renders_history_including_curator_reply(): void
    {
        $user = User::factory()->create();
        $curator = User::factory()->create(['name' => 'Куратор Маша']);

        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => 'Здравствуйте', 'is_read' => true]);
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'curator',
            'answered_by' => $curator->id,
            'text' => 'Намасте! Чем помочь?',
            'is_read' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(StudentChat::class)
            ->assertSee('Здравствуйте')
            ->assertSee('Намасте! Чем помочь?')
            ->assertSee('Куратор Маша');
    }
}
