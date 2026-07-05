<?php

declare(strict_types=1);

namespace Tests\Feature\Reminders;

use App\Models\ReminderSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderSuggestionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuggestion(User $user, int $ageDays, string $status = ReminderSuggestion::STATUS_PENDING): ReminderSuggestion
    {
        $suggestion = ReminderSuggestion::create([
            'user_id' => $user->id,
            'source_type' => ReminderSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => random_int(1, 1_000_000),
            'detected_text' => 'Тест',
            'confidence' => 0.9,
            'status' => $status,
        ]);
        $suggestion->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $suggestion;
    }

    public function test_stale_pending_suggestion_is_marked_expired(): void
    {
        $user = User::factory()->create();
        $stale = $this->makeSuggestion($user, 15);

        $this->artisan('reminders:expire-stale-suggestions')->assertExitCode(0);

        $this->assertSame(ReminderSuggestion::STATUS_EXPIRED, $stale->fresh()->status);
    }

    public function test_fresh_pending_suggestion_is_untouched(): void
    {
        $user = User::factory()->create();
        $fresh = $this->makeSuggestion($user, 2);

        $this->artisan('reminders:expire-stale-suggestions')->assertExitCode(0);

        $this->assertSame(ReminderSuggestion::STATUS_PENDING, $fresh->fresh()->status);
    }

    public function test_already_resolved_suggestion_is_untouched(): void
    {
        $user = User::factory()->create();
        $approved = $this->makeSuggestion($user, 30, ReminderSuggestion::STATUS_APPROVED);

        $this->artisan('reminders:expire-stale-suggestions')->assertExitCode(0);

        $this->assertSame(ReminderSuggestion::STATUS_APPROVED, $approved->fresh()->status);
    }
}
