<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Публичный эндпоинт живого веб-чата (H536 Phase 3): гость и студент шлют
 * сообщение, история читается, вывод экранирован, событие бродкастится.
 */
class PublicChatEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_posts_a_message_and_owns_a_guest_conversation(): void
    {
        Event::fake([ChatMessageSent::class]);

        $response = $this->postJson('/chat/message', ['text' => 'Здравствуйте, вопрос по курсу']);

        $response->assertOk()->assertJson(['ok' => true]);
        $conversationId = $response->json('conversation_id');
        $this->assertNotNull($conversationId);

        $thread = SupportConversation::findOrFail($conversationId);
        $this->assertNull($thread->user_id);
        $this->assertNotNull($thread->guest_token);
        $this->assertTrue($thread->isGuest());

        $this->assertDatabaseHas('chat_messages', [
            'support_conversation_id' => $conversationId,
            'user_id' => null,
            'role' => 'user',
            'text' => 'Здравствуйте, вопрос по курсу',
        ]);

        Event::assertDispatched(ChatMessageSent::class, fn ($e) => $e->message->support_conversation_id === $conversationId);
    }

    public function test_guest_second_message_reuses_the_same_conversation(): void
    {
        $first = $this->postJson('/chat/message', ['text' => 'Первое'])->json('conversation_id');
        $second = $this->postJson('/chat/message', ['text' => 'Второе'])->json('conversation_id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('support_conversations', 1);
        $this->assertSame(2, SupportConversation::find($first)->chatMessages()->count());
    }

    public function test_logged_in_student_posts_under_their_user(): void
    {
        Event::fake([ChatMessageSent::class]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/chat/message', ['text' => 'Я студент']);

        $response->assertOk();
        $thread = SupportConversation::findOrFail($response->json('conversation_id'));
        $this->assertSame($user->id, $thread->user_id);
        $this->assertNull($thread->guest_token);
        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Я студент',
        ]);
    }

    public function test_visitor_text_is_escaped_never_raw_in_the_response(): void
    {
        $response = $this->postJson('/chat/message', ['text' => '<script>alert(1)</script> привет']);

        $response->assertOk();
        $html = $response->json('message.html');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('привет', $html);
    }

    public function test_empty_and_whitespace_text_is_rejected(): void
    {
        $this->postJson('/chat/message', ['text' => ''])->assertStatus(422);
        $this->postJson('/chat/message', ['text' => '   '])->assertStatus(422);
    }

    public function test_overlong_text_is_rejected(): void
    {
        $this->postJson('/chat/message', ['text' => str_repeat('а', 2001)])->assertStatus(422);
    }

    public function test_history_returns_guest_thread_messages_in_order(): void
    {
        $this->postJson('/chat/message', ['text' => 'Раз']);
        $this->postJson('/chat/message', ['text' => 'Два']);

        $response = $this->getJson('/chat/history');

        $response->assertOk();
        $this->assertCount(2, $response->json('messages'));
        $this->assertSame('Раз', strip_tags($response->json('messages.0.html')));
        $this->assertSame('Два', strip_tags($response->json('messages.1.html')));
    }

    public function test_history_is_empty_for_a_fresh_visitor(): void
    {
        $response = $this->getJson('/chat/history');

        $response->assertOk()->assertJson(['ok' => true, 'conversation_id' => null, 'messages' => []]);
    }

    // --- H1199: Jivo-паритет S4, необязательный телефон/почта → Lead-строка ---

    public function test_email_and_phone_capture_a_lead_when_flag_is_on(): void
    {
        config(['features.support_lead_capture' => true]);

        $response = $this->postJson('/chat/message', [
            'text' => 'Вопрос про курс',
            'name' => 'Мария',
            'email' => 'maria@example.com',
            'phone' => '+79995554433',
        ]);

        $response->assertOk();
        $thread = SupportConversation::findOrFail($response->json('conversation_id'));

        $this->assertNotNull($thread->lead_id);
        $this->assertSame('maria@example.com', $thread->contact_email);
        $this->assertSame('+79995554433', $thread->contact_phone);
        $this->assertDatabaseHas('leads', ['email' => 'maria@example.com', 'contact' => '+79995554433']);
    }

    public function test_contact_fields_are_ignored_when_flag_is_off(): void
    {
        config(['features.support_lead_capture' => false]);

        $response = $this->postJson('/chat/message', [
            'text' => 'Вопрос',
            'email' => 'off@example.com',
        ]);

        $response->assertOk();
        $thread = SupportConversation::findOrFail($response->json('conversation_id'));

        $this->assertNull($thread->lead_id);
        $this->assertDatabaseMissing('leads', ['email' => 'off@example.com']);
    }

    public function test_message_without_contact_still_works_when_flag_is_on(): void
    {
        config(['features.support_lead_capture' => true]);

        $response = $this->postJson('/chat/message', ['text' => 'Просто вопрос, без контактов']);

        $response->assertOk();
        $thread = SupportConversation::findOrFail($response->json('conversation_id'));
        $this->assertNull($thread->lead_id);
    }

    public function test_invalid_email_is_rejected(): void
    {
        config(['features.support_lead_capture' => true]);

        $this->postJson('/chat/message', ['text' => 'Х', 'email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_second_message_does_not_overwrite_already_captured_lead(): void
    {
        config(['features.support_lead_capture' => true]);

        $first = $this->postJson('/chat/message', ['text' => 'Раз', 'email' => 'a@example.com']);
        $threadId = $first->json('conversation_id');

        $this->postJson('/chat/message', ['text' => 'Два', 'email' => 'b@example.com']);

        $thread = SupportConversation::findOrFail($threadId);
        $this->assertSame('a@example.com', $thread->contact_email);
        $this->assertDatabaseMissing('leads', ['email' => 'b@example.com']);
    }
}
