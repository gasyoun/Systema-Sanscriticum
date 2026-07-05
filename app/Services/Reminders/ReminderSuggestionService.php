<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Models\ReminderSuggestion;
use App\Models\ScheduledReminder;
use App\Models\User;

/**
 * Единая точка approve/dismiss для ReminderSuggestion — переиспользуется и
 * Filament-очередью (ReminderSuggestionResource), и инлайн-баннером в Helpdesk,
 * чтобы логика подтверждения жила в одном месте (см. H187, ruling #3).
 */
class ReminderSuggestionService
{
    /** @param  array{message: string, scheduled_for: \DateTimeInterface|string, channels?: array<int, string>}  $data */
    public function approve(ReminderSuggestion $suggestion, array $data, ?User $curator): ScheduledReminder
    {
        $channels = $data['channels'] ?? ['to_telegram'];

        $reminder = ScheduledReminder::create([
            'user_id' => $suggestion->user_id,
            'created_by' => $curator?->id,
            'message' => $data['message'],
            'to_telegram' => in_array('to_telegram', $channels, true),
            'to_vk' => in_array('to_vk', $channels, true),
            'to_email' => in_array('to_email', $channels, true),
            'scheduled_for' => $data['scheduled_for'],
        ]);

        $suggestion->update([
            'status' => ReminderSuggestion::STATUS_APPROVED,
            'resolved_by' => $curator?->id,
            'resolved_at' => now(),
            'scheduled_reminder_id' => $reminder->id,
        ]);

        return $reminder;
    }

    public function dismiss(ReminderSuggestion $suggestion, ?User $curator): void
    {
        $suggestion->update([
            'status' => ReminderSuggestion::STATUS_DISMISSED,
            'resolved_by' => $curator?->id,
            'resolved_at' => now(),
        ]);
    }
}
