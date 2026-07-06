<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Support\SupportAnswerEventLogger;
use App\Services\Support\SupportAnswerSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAnswerSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuggestion(User $user): SupportAnswerSuggestion
    {
        return SupportAnswerSuggestion::create([
            'user_id' => $user->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'detected_text' => 'Есть ссылка на занятие?',
            'draft_text' => 'Ближайшее занятие — завтра. Ссылка: https://zoom.us/j/1',
            'facts' => ['type' => 'zoom'],
            'confidence' => 0.9,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_accept_marks_accepted_and_logs_event(): void
    {
        $curator = User::factory()->create();
        $student = User::factory()->create();
        $suggestion = $this->makeSuggestion($student);

        app(SupportAnswerSuggestionService::class)->accept($suggestion, $curator);

        $suggestion->refresh();
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $suggestion->status);
        $this->assertSame($curator->id, $suggestion->resolved_by);
        $this->assertNotNull($suggestion->resolved_at);
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_ACCEPTED,
        ]);
    }

    public function test_edit_marks_edited_and_logs_event(): void
    {
        $curator = User::factory()->create();
        $student = User::factory()->create();
        $suggestion = $this->makeSuggestion($student);

        app(SupportAnswerSuggestionService::class)->edit($suggestion, $curator);

        $this->assertSame(SupportAnswerSuggestion::STATUS_EDITED, $suggestion->fresh()->status);
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_EDITED,
        ]);
    }

    public function test_discard_marks_discarded_and_logs_event(): void
    {
        $curator = User::factory()->create();
        $student = User::factory()->create();
        $suggestion = $this->makeSuggestion($student);

        app(SupportAnswerSuggestionService::class)->discard($suggestion, $curator);

        $this->assertSame(SupportAnswerSuggestion::STATUS_DISCARDED, $suggestion->fresh()->status);
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_DISCARDED,
        ]);
    }

    public function test_already_resolved_suggestion_is_not_transitioned_again(): void
    {
        $curator = User::factory()->create();
        $student = User::factory()->create();
        $suggestion = $this->makeSuggestion($student);

        app(SupportAnswerSuggestionService::class)->accept($suggestion, $curator);
        app(SupportAnswerSuggestionService::class)->discard($suggestion, $curator);

        // Второй переход не срабатывает — статус остаётся accepted, событие discard не пишется.
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $suggestion->fresh()->status);
        $this->assertDatabaseMissing('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_DISCARDED,
        ]);
    }
}
