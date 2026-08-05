<?php

declare(strict_types=1);

namespace Tests\Feature\StartChteniya;

use App\Models\Course;
use App\Models\Payment;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\User;
use App\Support\StartChteniyaSrsDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2111 — «в колоду» from the tap-token panel.
 *
 * Asserted against the REAL pinned freeze (resources/data/cohort_start_chteniya/
 * hitopadesa-0.json), not a fixture: the point of the endpoint is that the card's
 * content comes out of the vendored pack server-side, so a test that fed it its own
 * token shape would prove nothing about that. The gates themselves are pinned
 * separately from H2110's page tests because a POST route can drift out of the gate
 * a GET route keeps.
 */
class CabinetReadingPackSrsTest extends TestCase
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

    private function entitledUser(): User
    {
        $course = $this->cohortCourse();
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

    /** @return array<string,mixed> */
    private function freeze(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('data/cohort_start_chteniya/hitopadesa-0.json')),
            true,
        );
    }

    /** @test */
    public function an_entitled_student_adds_the_tapped_lemma_to_their_private_deck(): void
    {
        $this->enableBothGates();
        $user = $this->entitledUser();

        $response = $this->actingAs($user)
            ->from('/dvaram/reading/hitopadesa-0')
            ->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0]);

        $response->assertRedirect('/dvaram/reading/hitopadesa-0');
        $response->assertSessionHas('reading_srs_status', 'added');

        $deck = SrsDeck::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($deck);
        $this->assertSame(StartChteniyaSrsDeck::DECK_SLUG, $deck->slug);
        // Private-by-ownership is the only entitlement mechanism SrsController honours.
        $this->assertSame('private', $deck->visibility);

        $card = SrsCard::query()->where('deck_id', $deck->id)->sole();
        // Real values from the freeze's very first token — never client-supplied.
        $this->assertSame('hitopadesa-0', $card->fields['pack']);
        $this->assertSame('sidDi', $card->fields['lemma_slp1']);
        $this->assertSame('siddhiḥ', $card->fields['surface']);
        $this->assertSame('успех', $card->fields['gloss_ru']);
        $this->assertSame('1', $card->fields['locus']);
    }

    /** @test */
    public function tapping_the_same_word_twice_does_not_create_a_second_card(): void
    {
        $this->enableBothGates();
        $user = $this->entitledUser();

        $this->actingAs($user)->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0]);
        $second = $this->actingAs($user)
            ->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0]);

        $second->assertSessionHas('reading_srs_status', 'duplicate');
        $this->assertSame(1, SrsCard::query()->count());
    }

    /** @test */
    public function a_lemma_the_bulk_import_already_gave_them_is_not_duplicated(): void
    {
        // The whole reason StartChteniyaSrsDeck exists: H2106's import and this POST share
        // one deck AND one dedupe key, so the two writers cannot double a lemma.
        $this->enableBothGates();
        $user = $this->entitledUser();

        $fixture = tempnam(sys_get_temp_dir(), 'sc_srs_h2111_').'.tsv';
        file_put_contents($fixture, implode("\n", [
            "pack\tlemma_slp1\tsurface\tgloss_ru\tgloss_en\tlocus",
            "hitopadesa-0\tsidDi\tsiddhiḥ\tуспех\tsuccess\t1",
        ])."\n");

        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $fixture])->assertSuccessful();
        @unlink($fixture);

        $this->assertSame(1, SrsCard::query()->count());

        $this->actingAs($user)
            ->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0])
            ->assertSessionHas('reading_srs_status', 'duplicate');

        $this->assertSame(1, SrsDeck::query()->count());
        $this->assertSame(1, SrsCard::query()->count());
    }

    /** @test */
    public function a_logged_in_student_without_the_cohort_cannot_post(): void
    {
        $this->enableBothGates();
        $this->cohortCourse();

        $this->actingAs(User::factory()->create())
            ->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0])
            ->assertNotFound();

        $this->assertSame(0, SrsDeck::query()->count());
    }

    /** @test */
    public function a_guest_is_redirected_to_login(): void
    {
        $this->enableBothGates();

        $this->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0])
            ->assertRedirect('/login');
    }

    /** @test */
    public function either_flag_off_404s_even_for_an_entitled_student(): void
    {
        config(['features.kosha_reader' => true, 'features.start_chteniya_cohort' => false]);
        $user = $this->entitledUser();
        $this->actingAs($user)
            ->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0])
            ->assertNotFound();

        config(['features.kosha_reader' => false, 'features.start_chteniya_cohort' => true]);
        $this->actingAs($user)
            ->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 0])
            ->assertNotFound();

        $this->assertSame(0, SrsCard::query()->count());
    }

    /** @test */
    public function an_unoffered_slug_or_an_out_of_range_position_404s(): void
    {
        $this->enableBothGates();
        $user = $this->entitledUser();

        // nala-1's feed genuinely exists but the cabinet does not offer it.
        $this->actingAs($user)->post('/dvaram/reading/nala-1/srs', ['sentence' => 0, 'token' => 0])
            ->assertNotFound();
        $this->actingAs($user)->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 99999, 'token' => 0])
            ->assertNotFound();
        $this->actingAs($user)->post('/dvaram/reading/hitopadesa-0/srs', ['sentence' => 0, 'token' => 99999])
            ->assertNotFound();

        $this->assertSame(0, SrsCard::query()->count());
    }

    /** @test */
    public function a_post_without_positions_is_rejected(): void
    {
        $this->enableBothGates();

        $this->actingAs($this->entitledUser())
            ->post('/dvaram/reading/hitopadesa-0/srs', [])
            ->assertSessionHasErrors(['sentence', 'token']);

        $this->assertSame(0, SrsCard::query()->count());
    }

    /** @test */
    public function the_cabinet_page_offers_one_button_per_token_that_has_a_lemma(): void
    {
        // The degrade rule made structural: a token with no lemma gets no button, so the
        // button count must equal the lemma-bearing token count — not the token count.
        // (In the current freeze every token happens to carry a lemma; this assertion
        // still binds the rule rather than the coincidence.)
        $this->enableBothGates();

        $html = $this->actingAs($this->entitledUser())
            ->get('/dvaram/reading/hitopadesa-0')
            ->assertOk()
            ->getContent();

        $withLemma = 0;
        foreach ($this->freeze()['sentences'] as $sentence) {
            foreach ($sentence['tokens'] as $token) {
                if (! empty($token['lemma'])) {
                    $withLemma++;
                }
            }
        }

        $this->assertGreaterThan(0, $withLemma);
        $this->assertSame($withLemma, substr_count((string) $html, '+ в колоду'));
    }

    /** @test */
    public function the_public_demo_page_gets_no_add_to_srs_controls(): void
    {
        config(['features.kosha_reader' => true, 'features.start_chteniya_cohort' => false]);

        $response = $this->get('/reading/kosha-demo');

        $response->assertOk();
        $response->assertDontSee('в колоду');
        $response->assertDontSee('/srs', false);
    }
}
