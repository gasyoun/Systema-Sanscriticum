<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\FollowUpTask;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Support-dialog follow-ups via existing FollowUpTask (H2381).
 * Not CRM deal tasks and not academic ScheduledReminder.
 */
class SupportFollowUpService
{
    public function create(
        SupportConversation $thread,
        Carbon|string $dueAt,
        ?User $assignee = null,
        string $type = FollowUpTask::TYPE_MESSAGE,
        ?string $note = null,
    ): FollowUpTask {
        if (! config('features.support_follow_up_tasks')) {
            throw new InvalidArgumentException('support_follow_up_tasks flag is OFF.');
        }

        $due = $dueAt instanceof Carbon ? $dueAt : Carbon::parse($dueAt);

        return FollowUpTask::create([
            'deal_id' => null,
            'support_conversation_id' => $thread->id,
            'assigned_to' => $assignee?->id,
            'type' => $type,
            'due_at' => $due,
            'note' => $note,
            'done_at' => null,
        ]);
    }

    /**
     * Open support follow-ups for a conversation (due/overdue surface).
     *
     * @return Collection<int, FollowUpTask>
     */
    public function openForConversation(SupportConversation $thread): Collection
    {
        return FollowUpTask::query()
            ->where('support_conversation_id', $thread->id)
            ->open()
            ->orderBy('due_at')
            ->get();
    }

    public function complete(FollowUpTask $task): void
    {
        if ($task->support_conversation_id === null) {
            throw new InvalidArgumentException('Not a support follow-up task.');
        }

        $task->markDone();
    }
}
