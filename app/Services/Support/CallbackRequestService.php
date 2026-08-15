<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\FollowUpTask;
use App\Models\User;
use App\Services\Activity\ActivityTracker;
use App\Support\Telephony\CallEvent;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * H2747 — Phase 0/1 consented callback request (H2486 packet §4.3/§8, Phase
 * 0/1). Writes ONLY:
 *
 *  1. FollowUpTask {type: call, support_conversation_id} — reuses the existing
 *     store (H2381 nullable support_conversation_id), NOT a new task table.
 *  2. CallEvent::requested, persisted as an ActivityEvent row (packet §4.2
 *     option 1 — the default; no call_events channel table).
 *  3. optional users.phone update, from the explicit consented form only.
 *
 * No provider HTTP, no CallProvider, no PSTN. Behind
 * features.telephony_callback_request (default OFF). Logged-in students
 * only (Q-law-4 unanswered — packet §5 guardrail; guests stay off).
 *
 * @see docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md
 */
class CallbackRequestService
{
    public function __construct(
        private readonly SupportConversationManager $conversations,
        private readonly ActivityTracker $activity,
    ) {}

    public function requestCallback(User $user, string $phone, ?string $note, ?Request $request = null): FollowUpTask
    {
        if (! config('features.telephony_callback_request')) {
            throw new InvalidArgumentException('telephony_callback_request flag is OFF.');
        }

        $phone = trim($phone);
        if ($phone !== '' && $user->phone !== $phone) {
            $user->forceFill(['phone' => $phone])->save();
        }

        $thread = $this->conversations->openFor($user);

        $note = $note !== null ? trim($note) : '';

        $task = FollowUpTask::create([
            'deal_id' => null,
            'support_conversation_id' => $thread->id,
            'assigned_to' => null,
            'type' => FollowUpTask::TYPE_CALL,
            'due_at' => now(),
            'note' => $note !== '' ? $note : "Запрос обратного звонка: {$phone}",
            'done_at' => null,
        ]);

        $event = CallEvent::requested($user->id, $thread->id, $task->id);

        // Telemetry only — CallEvent is not persisted itself (packet §4.2);
        // the FollowUpTask row above is the source of truth. ActivityTracker
        // swallows write failures so a logging hiccup never blocks the request.
        $this->activity->logEvent(
            user: $user,
            session: null,
            type: $event->type,
            data: [
                'support_conversation_id' => $event->supportConversationId,
                'follow_up_task_id' => $event->followUpTaskId,
            ],
            request: $request,
        );

        return $task;
    }
}
