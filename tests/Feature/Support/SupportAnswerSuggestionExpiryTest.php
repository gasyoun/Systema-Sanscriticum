<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAnswerSuggestionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuggestion(User $user, string $status, int $ageDays): SupportAnswerSuggestion
    {
        $suggestion = SupportAnswerSuggestion::create([
            'user_id' => $user->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => random_int(1, 1_000_000),
            'category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'detected_text' => 'Есть ссылка?',
            'draft_text' => 'Ссылка: https://zoom.us/j/1',
            'confidence' => 0.9,
            'status' => $status,
        ]);

        // created_at не fillable — проставляем напрямую.
        $suggestion->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $suggestion;
    }

    public function test_stale_pending_suggestion_is_marked_expired(): void
    {
        $user = User::factory()->create();
        $suggestion = $this->makeSuggestion($user, SupportAnswerSuggestion::STATUS_PENDING, 20);

        $this->artisan('support:expire-stale-answer-suggestions')->assertSuccessful();

        $this->assertSame(SupportAnswerSuggestion::STATUS_EXPIRED, $suggestion->fresh()->status);
    }

    public function test_fresh_pending_suggestion_is_untouched(): void
    {
        $user = User::factory()->create();
        $suggestion = $this->makeSuggestion($user, SupportAnswerSuggestion::STATUS_PENDING, 2);

        $this->artisan('support:expire-stale-answer-suggestions')->assertSuccessful();

        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
    }

    public function test_already_resolved_suggestion_is_untouched(): void
    {
        $user = User::factory()->create();
        $suggestion = $this->makeSuggestion($user, SupportAnswerSuggestion::STATUS_ACCEPTED, 30);

        $this->artisan('support:expire-stale-answer-suggestions')->assertSuccessful();

        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $suggestion->fresh()->status);
    }
}
