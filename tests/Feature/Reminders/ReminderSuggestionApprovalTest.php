<?php

declare(strict_types=1);

namespace Tests\Feature\Reminders;

use App\Models\ReminderSuggestion;
use App\Models\ScheduledReminder;
use App\Models\User;
use App\Services\Reminders\ReminderSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderSuggestionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuggestion(User $user): ReminderSuggestion
    {
        return ReminderSuggestion::create([
            'user_id' => $user->id,
            'source_type' => ReminderSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'detected_text' => 'Напомнить про отзыв о курсе',
            'suggested_date' => now()->addDays(5),
            'confidence' => 0.9,
            'status' => ReminderSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_approve_creates_linked_scheduled_reminder(): void
    {
        $curator = User::factory()->create();
        $user = User::factory()->create();
        $suggestion = $this->makeSuggestion($user);

        $reminder = app(ReminderSuggestionService::class)->approve($suggestion, [
            'message' => 'Отредактированный текст напоминания',
            'scheduled_for' => now()->addDays(7),
            'channels' => ['to_telegram', 'to_email'],
        ], $curator);

        $this->assertInstanceOf(ScheduledReminder::class, $reminder);
        $this->assertSame('Отредактированный текст напоминания', $reminder->message);
        $this->assertTrue($reminder->to_telegram);
        $this->assertTrue($reminder->to_email);
        $this->assertFalse($reminder->to_vk);

        $suggestion->refresh();
        $this->assertSame(ReminderSuggestion::STATUS_APPROVED, $suggestion->status);
        $this->assertSame($curator->id, $suggestion->resolved_by);
        $this->assertSame($reminder->id, $suggestion->scheduled_reminder_id);
        $this->assertNotNull($suggestion->resolved_at);
    }

    public function test_dismiss_does_not_create_scheduled_reminder(): void
    {
        $curator = User::factory()->create();
        $user = User::factory()->create();
        $suggestion = $this->makeSuggestion($user);

        app(ReminderSuggestionService::class)->dismiss($suggestion, $curator);

        $suggestion->refresh();
        $this->assertSame(ReminderSuggestion::STATUS_DISMISSED, $suggestion->status);
        $this->assertNull($suggestion->scheduled_reminder_id);
        $this->assertDatabaseCount('scheduled_reminders', 0);
    }
}
