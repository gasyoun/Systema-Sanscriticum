<?php

declare(strict_types=1);

namespace Tests\Feature\StartChteniya;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\StartChteniyaProgress;
use App\Support\StartChteniyaSrsDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H2107 — student progress (token-lookup % + unique-lemma count) and the data
 * feeding the teacher stalled-lemma view. Asserted against the REAL pinned
 * freeze, same discipline as CabinetReadingPackSrsTest.
 */
class ReadingProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function cohortCourse(): Course
    {
        $course = Course::factory()->create(['slug' => 'start-chteniya']);
        config(['start_chteniya.course_slug' => $course->slug]);

        return $course;
    }

    private function entitledUser(?Course $course = null): User
    {
        $course ??= $this->cohortCourse();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $user->fresh();
    }

    private function enableBothGates(): void
    {
        config([
            'features.kosha_reader' => true,
            'features.start_chteniya_cohort' => true,
        ]);
    }

    /** @test */
    public function opening_a_word_logs_a_lookup_event_once_per_position(): void
    {
        $this->enableBothGates();
        $user = $this->entitledUser();

        $this->actingAs($user)
            ->post('/dvaram/reading/hitopadesa-0/lookup', ['sentence' => 0, 'token' => 0])
            ->assertNoContent();
        $this->actingAs($user)
            ->post('/dvaram/reading/hitopadesa-0/lookup', ['sentence' => 0, 'token' => 0])
            ->assertNoContent();

        $this->assertSame(2, ActivityEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', ActivityEvent::TYPE_READING_TOKEN_LOOKUP)
            ->count());
    }

    /** @test */
    public function lookup_requires_entitlement_and_positions(): void
    {
        $this->enableBothGates();
        $course = $this->cohortCourse();

        $this->actingAs(User::factory()->create())
            ->post('/dvaram/reading/hitopadesa-0/lookup', ['sentence' => 0, 'token' => 0])
            ->assertNotFound();

        $this->actingAs($this->entitledUser($course))
            ->post('/dvaram/reading/hitopadesa-0/lookup', [])
            ->assertSessionHasErrors(['sentence', 'token']);
    }

    /** @test */
    public function lookup_percent_reflects_distinct_lemmas_looked_up(): void
    {
        $this->enableBothGates();
        $user = $this->entitledUser();

        $pack = json_decode(
            (string) file_get_contents(resource_path('data/cohort_start_chteniya/hitopadesa-0.json')),
            true,
        );
        $total = StartChteniyaProgress::lemmaBearingTokenCount($pack);
        $this->assertGreaterThan(0, $total);

        $this->assertSame(0, StartChteniyaProgress::lookupPercentFor($user, $pack));

        $this->actingAs($user)->post('/dvaram/reading/hitopadesa-0/lookup', ['sentence' => 0, 'token' => 0]);

        $expected = (int) round(1 / $total * 100);
        $this->assertSame($expected, StartChteniyaProgress::lookupPercentFor($user->fresh(), $pack));
    }

    /** @test */
    public function unique_lemma_count_reflects_the_cohort_srs_deck(): void
    {
        $this->enableBothGates();
        $user = $this->entitledUser();

        $this->assertSame(0, StartChteniyaProgress::uniqueLemmaCountFor($user));

        $this->actingAs($user)->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0]);

        $this->assertSame(1, StartChteniyaProgress::uniqueLemmaCountFor($user->fresh()));
    }

    /** @test */
    public function top_stalled_lemmas_ranks_lookups_that_never_converted(): void
    {
        $this->enableBothGates();
        $course = $this->cohortCourse();
        $stuck = $this->entitledUser($course);
        $converted = $this->entitledUser($course);

        // Both students look up the same first token repeatedly.
        foreach ([$stuck, $converted] as $student) {
            $this->actingAs($student)->post('/dvaram/reading/hitopadesa-0/lookup', ['sentence' => 0, 'token' => 0]);
            $this->actingAs($student)->post('/dvaram/reading/hitopadesa-0/lookup', ['sentence' => 0, 'token' => 0]);
        }
        // Only $converted actually collects it.
        $this->actingAs($converted)->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0]);

        $rows = StartChteniyaProgress::topStalledLemmas(10);
        $this->assertNotEmpty($rows);

        $top = $rows->first();
        $this->assertSame('hitopadesa-0', $top['pack']);
        $this->assertSame(4, $top['lookups']); // 2 students × 2 opens each
        $this->assertSame(2, $top['students']);
        $this->assertSame(1, $top['added']); // only $converted collected it
        $this->assertSame(3, $top['stalled_score']); // 4 lookups - 1 converted student
    }

    /** @test */
    public function the_srs_deck_slug_matches_what_stalled_lemmas_queries(): void
    {
        // Guards the raw srs_decks subquery in StartChteniyaProgress::topStalledLemmas
        // against a table/column rename that the Eloquent-based paths would not catch.
        $this->assertTrue(Schema::hasTable('srs_decks'));
        $this->assertTrue(Schema::hasColumns('srs_decks', ['slug', 'user_id']));
        $this->assertSame('start-chteniya-cohort', StartChteniyaSrsDeck::DECK_SLUG);
    }
}
