<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a support chat message over Reverb (H536, Phase 1 building block).
 *
 * Dispatched by the public post endpoint (Phase 3) and the operator reply path
 * (Phase 5); the visitor bubble and the Helpdesk inbox subscribe to the private
 * per-conversation channel and render {@see broadcastWith()} live.
 *
 * Security: the payload carries {@see ChatMessage::htmlForWeb()} (whitelist-escaped),
 * never the raw text — visitor input must not reach a peer or operator unescaped.
 */
class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ChatMessage $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('support.conversation.'.$this->message->support_conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'role' => $this->message->role,
            'html' => $this->message->htmlForWeb(),
            'created_at' => optional($this->message->created_at)->toIso8601String(),
        ];
    }
}
