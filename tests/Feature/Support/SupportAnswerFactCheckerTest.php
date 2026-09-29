<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Support\SupportAnswerFactChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4589: SupportAnswerFactChecker re-resolves category facts at check-time
 * and diffs them against the stored draft snapshot. Ground truth for the
 * fixtures below is a real Schedule row per student — no LLM, no network.
 */
class SupportAnswerFactCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_link_and_schedule_passes(): void
    {
        $student = User::factory()->create();
        $class = $this->makeUpcomingClassFor($student, 'https://zoom.us/j/111');

        $suggestion = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'detected_text' => 'Есть ссылка на занятие?',
            'draft_text' => 'Ссылка: https://zoom.us/j/111',
            'facts' => ['type' => 'zoom', 'link' => 'https://zoom.us/j/111', 'schedule_id' => $class->id],
            'confidence' => 0.9,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);

        $result = app(SupportAnswerFactChecker::class)->check($suggestion);

        $this->assertTrue($result['checked']);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['mismatches']);
    }

    public function test_link_drift_between_suggestion_and_check_is_flagged(): void
    {
        $student = User::factory()->create();
        $class = $this->makeUpcomingClassFor($student, 'https://zoom.us/j/111');

        // Snapshot captured earlier claims a link that no longer matches the
        // live Zoom link on the same schedule row (link rotated meanwhile).
        $suggestion = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'detected_text' => 'Есть ссылка на занятие?',
            'draft_text' => 'Ссылка: https://zoom.us/j/STALE',
            'facts' => ['type' => 'zoom', 'link' => 'https://zoom.us/j/STALE', 'schedule_id' => $class->id],
            'confidence' => 0.9,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);

        $result = app(SupportAnswerFactChecker::class)->check($suggestion);

        $this->assertTrue($result['checked']);
        $this->assertFalse($result['ok']);
        $this->assertSame(['link'], $result['mismatches']);
    }

    public function test_unverifiable_category_is_not_checked(): void
    {
        // Materials (F) is out of this grounding layer's declared scope —
        // and a minimal facts snapshot with only 'type' has nothing to
        // re-verify either way. Both must pass through untouched.
        $student = User::factory()->create();

        $suggestion = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'detected_text' => 'Есть ссылка на занятие?',
            'draft_text' => 'Ближайшее занятие — завтра.',
            'facts' => ['type' => 'zoom'],
            'confidence' => 0.9,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);

        $result = app(SupportAnswerFactChecker::class)->check($suggestion);

        $this->assertFalse($result['checked']);
        $this->assertTrue($result['ok']);
    }

    public function test_guest_public_pricing_drift_is_flagged(): void
    {
        $course = Course::factory()->create();
        $course->tariffs()->create(['title' => 'Весь курс', 'type' => 'full', 'price' => 5000, 'is_active' => true]);

        $suggestion = SupportAnswerSuggestion::create([
            'user_id' => null,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 1,
            'category' => SupportAnswerSuggestion::CATEGORY_PAYMENT,
            'detected_text' => 'Сколько стоит курс?',
            'draft_text' => 'от 3000 ₽',
            'facts' => [
                'type' => 'public_pricing',
                'courses' => [['course' => $course->title, 'from_price' => 3000.0]],
            ],
            'confidence' => 0.5,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);

        $result = app(SupportAnswerFactChecker::class)->check($suggestion);

        $this->assertTrue($result['checked']);
        $this->assertFalse($result['ok']);
        $this->assertSame(['courses'], $result['mismatches']);
    }

    private function makeUpcomingClassFor(User $student, string $link): Schedule
    {
        $group = Group::create(['name' => 'Группа H4589']);
        $student->groups()->attach($group->id);

        return Schedule::create([
            'title' => 'Занятие',
            'start' => now()->addDay(),
            'link' => $link,
            'group_id' => $group->id,
        ]);
    }
}
