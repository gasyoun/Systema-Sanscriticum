<?php

namespace Tests\Feature\Broadcasting;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Tests\TestCase;

class ChatMessageBroadcastTest extends TestCase
{
    public function test_event_is_broadcastable(): void
    {
        $this->assertInstanceOf(
            ShouldBroadcast::class,
            new ChatMessageSent(new ChatMessage(['support_conversation_id' => 1, 'text' => 'hi', 'role' => 'user']))
        );
    }

    public function test_broadcasts_on_the_private_per_conversation_channel(): void
    {
        $event = new ChatMessageSent(new ChatMessage(['support_conversation_id' => 42, 'text' => 'hi', 'role' => 'user']));

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-support.conversation.42', $channel->name);
        $this->assertSame('chat.message', $event->broadcastAs());
    }

    public function test_payload_is_escaped_never_raw_visitor_text(): void
    {
        $message = new ChatMessage([
            'support_conversation_id' => 1,
            'role' => 'user',
            'text' => '<script>alert(1)</script> hello',
        ]);

        $payload = (new ChatMessageSent($message))->broadcastWith();

        // htmlForWeb() whitelist-escapes; the raw <script> must never reach a peer/operator.
        $this->assertStringNotContainsString('<script>', $payload['html']);
        $this->assertStringContainsString('hello', $payload['html']);
        $this->assertSame('user', $payload['role']);
    }

    public function test_reverb_connection_is_configured(): void
    {
        $reverb = config('broadcasting.connections.reverb');

        $this->assertIsArray($reverb);
        $this->assertSame('reverb', $reverb['driver']);
    }
}
