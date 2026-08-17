<?php

declare(strict_types=1);

namespace Tests\Feature\CourseCohort;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Support\CourseReadingPacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2168 — ReadingPackController generalized to multi-course, multi-pack.
 *
 * Two things are pinned here and they fail for different reasons, so neither is
 * folded into a single "it 404s" assertion:
 *
 *  - the NEW course path: an entitled student sees exactly their own course's packs,
 *    in course order, and nobody else's — a payment on Nalopākhyāna must never open
 *    the Subhāṣita reader (the failure mode H2105's single cohort could not have);
 *  - the OLD surfaces: /reading/kosha-demo renders byte-identically whether the
 *    course path is off or on, and still reads its historical legacy feed rather
 *    than the H2165 freeze's own nala-1 copy.
 *
 * Every pack asserted against is the vendored H2165 freeze
 * (resources/data/nala_subhashita/, sha256-verified by
 * scripts/vendor_nala_subhashita_packs.py) — real kosha data, not a fixture invented
 * here.
 */
class CourseReadingPackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** Test-only Course row for a cohort-course config key. Ops owns the real one. */
    private function cohortCourse(string $configKey, string $courseSlug): Course
    {
        $course = Course::factory()->create(['slug' => $courseSlug]);
        config(["cohort_courses.{$configKey}.course_slug" => $course->slug]);

        return $course;
    }

    private function enable(string $configKey): void
    {
        config(["cohort_courses.{$configKey}.enabled" => true]);
    }

    private function payFor(User $user, Course $course): void
    {
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
    }

    /** A student who really bought the given cohort course. */
    private function entitledUser(string $configKey, string $courseSlug): User
    {
        $this->enable($configKey);
        $course = $this->cohortCourse($configKey, $courseSlug);
        $user = User::factory()->create();
        $this->payFor($user, $course);

        return $user->fresh();
    }

    /** @test */
    public function guest_is_redirected_to_login_not_shown_a_course_pack(): void
    {
        $this->enable('nalopakhyana');
        $this->cohortCourse('nalopakhyana', 'nalopakhyana');

        $this->get('/dvaram/reading/kurs/nalopakhyana')->assertRedirect('/login');
        $this->get('/dvaram/reading/kurs/nalopakhyana/nala-1')->assertRedirect('/login');
    }

    /** @test */
    public function logged_in_non_payer_gets_404_not_the_reader(): void
    {
        $this->enable('nalopakhyana');
        $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dvaram/reading/kurs/nalopakhyana')->assertNotFound();
        $this->actingAs($user)->get('/dvaram/reading/kurs/nalopakhyana/nala-1')->assertNotFound();
    }

    /** @test */
    public function disabled_course_404s_even_for_a_real_payer(): void
    {
        $course = $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $user = User::factory()->create();
        $this->payFor($user, $course);
        config(['cohort_courses.nalopakhyana.enabled' => false]);

        $this->actingAs($user->fresh())->get('/dvaram/reading/kurs/nalopakhyana')->assertNotFound();
        $this->actingAs($user->fresh())->get('/dvaram/reading/kurs/nalopakhyana/nala-1')->assertNotFound();
    }

    /** @test */
    public function unknown_course_slug_404s(): void
    {
        $user = $this->entitledUser('nalopakhyana', 'nalopakhyana');

        $this->actingAs($user)->get('/dvaram/reading/kurs/not-a-real-course')->assertNotFound();
        $this->actingAs($user)->get('/dvaram/reading/kurs/not-a-real-course/nala-1')->assertNotFound();
    }

    /** @test */
    public function entitled_nala_student_sees_all_three_packs_in_narrative_order(): void
    {
        $user = $this->entitledUser('nalopakhyana', 'nalopakhyana');

        $res = $this->actingAs($user)->get('/dvaram/reading/kurs/nalopakhyana')->assertOk();

        // MBh 3.50 -> 3.51 -> 3.52, the H2168 sequential default.
        $res->assertSeeInOrder(['Nala 1', 'Nala 2', 'Nala 3'], false);
        $res->assertSee('Налопакхьяна', false);
    }

    /** @test */
    public function entitled_nala_student_can_open_every_pack_of_the_course(): void
    {
        $user = $this->entitledUser('nalopakhyana', 'nalopakhyana');

        foreach (['nala-1', 'nala-2', 'nala-3'] as $slug) {
            $this->actingAs($user)
                ->get('/dvaram/reading/kurs/nalopakhyana/'.$slug)
                ->assertOk()
                ->assertSee('Mahābhārata', false);
        }
    }

    /** @test */
    public function a_payment_on_one_cohort_course_never_opens_the_other_ones_reader(): void
    {
        $nalaUser = $this->entitledUser('nalopakhyana', 'nalopakhyana');
        $this->enable('subhashita');
        $this->cohortCourse('subhashita', 'subhashita');

        $this->actingAs($nalaUser)->get('/dvaram/reading/kurs/subhashita')->assertNotFound();
        $this->actingAs($nalaUser)
            ->get('/dvaram/reading/kurs/subhashita/subhashita-beginner')
            ->assertNotFound();
    }

    /** @test */
    public function a_pack_the_course_does_not_offer_404s_even_for_an_entitled_student(): void
    {
        $user = $this->entitledUser('nalopakhyana', 'nalopakhyana');

        // Real pack, wrong course.
        $this->actingAs($user)
            ->get('/dvaram/reading/kurs/nalopakhyana/subhashita-beginner')
            ->assertNotFound();

        // A slug the freeze does not carry at all.
        $this->actingAs($user)
            ->get('/dvaram/reading/kurs/nalopakhyana/hitopadesa-0')
            ->assertNotFound();
    }

    /** @test */
    public function entitled_subhashita_student_sees_the_single_pack_normalised_onto_the_existing_contract(): void
    {
        $user = $this->entitledUser('subhashita', 'subhashita');

        $this->actingAs($user)
            ->get('/dvaram/reading/kurs/subhashita')
            ->assertOk()
            ->assertSee('Субхашита', false)
            ->assertSee('Subhāṣita', false);

        $res = $this->actingAs($user)
            ->get('/dvaram/reading/kurs/subhashita/subhashita-beginner')
            ->assertOk();

        // Real content out of the freeze, reached through the sayings[]/lines[].chunks[]
        // -> sentences[]/tokens[] adapter: a saying locus, a split token form and its
        // Russian gloss. If the adapter silently produced an empty pack these fail.
        $res->assertSee('Subhāṣita 3128', false);
        $res->assertSee('dharmeṇa', false);
        $res->assertSee('по закону', false);
    }

    /** @test */
    public function the_subhashita_adapter_reports_honest_stats_and_never_fabricates_a_lemma(): void
    {
        $pack = CourseReadingPacks::read('subhashita-beginner');

        $this->assertSame('subhashita-beginner', $pack['slug']);
        $this->assertSame(106, $pack['stats']['sentences'], 'the freeze pins 106 sayings');
        $this->assertGreaterThan(0, $pack['stats']['tokens']);
        $this->assertLessThanOrEqual($pack['stats']['tokens'], $pack['stats']['linked_tokens']);

        $lemmaless = 0;
        foreach ($pack['sentences'] as $sentence) {
            foreach ($sentence['tokens'] as $token) {
                $this->assertArrayHasKey('form', $token);
                $this->assertArrayHasKey('lemma', $token);
                // No fabricated morphology/English gloss: the feed has neither.
                $this->assertArrayNotHasKey('morph', $token);
                $this->assertArrayNotHasKey('gloss', $token);
                if ($token['lemma'] === '') {
                    $lemmaless++;
                }
            }
        }

        // The freeze's own coverage note says a minority of tokens carry no lemma;
        // they must stay lemma-less rather than be invented.
        $this->assertGreaterThan(0, $lemmaless);
        $this->assertSame(
            $pack['stats']['tokens'] - $lemmaless,
            $pack['stats']['linked_tokens'],
        );
    }

    /** @test */
    public function course_pack_page_is_render_only_no_srs_button_and_no_lookup_telemetry(): void
    {
        $user = $this->entitledUser('nalopakhyana', 'nalopakhyana');

        $html = $this->actingAs($user)
            ->get('/dvaram/reading/kurs/nalopakhyana/nala-1')
            ->assertOk()
            ->getContent();

        // H2168's fence: reading-pack RENDER only — the tap-token/SRS half belongs to
        // a later handoff, so no button, no POST target, no telemetry hook.
        $this->assertStringNotContainsString('в колоду', $html);
        $this->assertStringNotContainsString('data-lookup-track', $html);
        $this->assertStringNotContainsString('/srs', $html);

        // H965's advisory difficulty block and its cross-corpus "graded reading"
        // list stay on the public demo: the list's own caption says only the text
        // above is wired up, which is false on a course offering three packs.
        $this->assertStringNotContainsString('Сложность текста', $html);
        $this->assertStringNotContainsString('Градуированное чтение', $html);
        $this->assertStringNotContainsString('Kirātārjunīya', $html);
    }

    /**
     * REGRESSION — the whole point of the handoff's "fail =" clause.
     *
     * @test
     */
    public function public_demo_route_renders_identically_whether_the_course_path_is_off_or_on(): void
    {
        config(['features.kosha_reader' => true]);

        // Both courses and their entitled students exist for BOTH renders, so the
        // single variable under test is the course path's own switch — not the
        // presence of Course/Payment rows, which any number of unrelated pages
        // legitimately react to.
        $this->entitledUser('nalopakhyana', 'nalopakhyana');
        $this->entitledUser('subhashita', 'subhashita');

        // Course path OFF (the shipped default: both switches false).
        config([
            'cohort_courses.nalopakhyana.enabled' => false,
            'cohort_courses.subhashita.enabled' => false,
        ]);
        $off = $this->get('/reading/kosha-demo')->assertOk()->getContent();

        // Course path ON.
        config([
            'cohort_courses.nalopakhyana.enabled' => true,
            'cohort_courses.subhashita.enabled' => true,
        ]);
        $on = $this->get('/reading/kosha-demo')->assertOk()->getContent();

        $this->assertSame(
            $off,
            $on,
            'H2168 is additive: turning the course reader on must not change one byte of /reading/kosha-demo.'
        );
    }

    /**
     * REGRESSION — the demo route keeps its historical feed even though the H2165
     * freeze also carries a `nala-1`. The two files really differ: the freeze copy
     * carries Russian glosses (430 tokens) and the legacy demo feed carries none, so
     * this assertion would fail loudly if the demo were ever repointed.
     *
     * @test
     */
    public function public_demo_route_still_reads_the_legacy_feed_not_the_freeze_copy(): void
    {
        config(['features.kosha_reader' => true]);
        $user = $this->entitledUser('nalopakhyana', 'nalopakhyana');

        $demo = $this->get('/reading/kosha-demo')->assertOk();
        $demo->assertSee('bṛhadaśva', false);
        $demo->assertDontSee('Брихадашва', false);

        // The same slug through the COURSE path is the freeze copy, RU glosses and all.
        $this->actingAs($user)
            ->get('/dvaram/reading/kurs/nalopakhyana/nala-1')
            ->assertOk()
            ->assertSee('Брихадашва', false);
    }

    /**
     * REGRESSION — H2110's «Старт чтения» in-cabinet reader is untouched: its own
     * flag+entitlement pair still governs it, and a Nala payer is not a cohort
     * student.
     *
     * @test
     */
    public function start_chteniya_cabinet_reader_is_unaffected_by_the_course_path(): void
    {
        config(['features.kosha_reader' => true, 'features.start_chteniya_cohort' => true]);
        $nalaUser = $this->entitledUser('nalopakhyana', 'nalopakhyana');

        $this->actingAs($nalaUser)->get('/dvaram/reading')->assertNotFound();
        $this->actingAs($nalaUser)->get('/dvaram/reading/hitopadesa-0')->assertNotFound();
    }
}
