<?php

declare(strict_types=1);

namespace Tests\Feature\StartChteniya;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2110 — the «Старт чтения» reading packs inside the cabinet.
 *
 * Two gates stack here and each is pinned separately, because they fail for different
 * reasons and a single "it 404s" test would not tell them apart: the reader feature flag
 * (ops switch) and StartChteniyaCohort::hasEntitlement() (a real paid cohort student).
 * The entitlement half is what makes this different from the public /reading/kosha-demo
 * route, which is flag-gated only.
 *
 * The pack itself is the vendored H2109 freeze copy — sha256-pinned against the freeze
 * MANIFEST by scripts/vendor_cohort_start_chteniya_packs.py, so these assertions are made
 * against real kosha data, not a fixture invented here.
 */
class CabinetReadingPackTest extends TestCase
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

    /** A user who really bought the cohort — the only kind that may read. */
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

    /** @test */
    public function guest_is_redirected_to_login_not_shown_the_pack(): void
    {
        $this->enableBothGates();

        $this->get('/dvaram/reading/hitopadesa-0')->assertRedirect('/login');
        $this->get('/dvaram/reading')->assertRedirect('/login');
    }

    /** @test */
    public function entitled_student_reads_hitopadesa_0_in_the_cabinet(): void
    {
        $this->enableBothGates();

        $response = $this->actingAs($this->entitledUser())->get('/dvaram/reading/hitopadesa-0');

        $response->assertOk();
        $response->assertSee('Hitopadeśa — Prastāvikā (opening)');
        // Real content from the pinned freeze: first sentence, first token, and its RU gloss.
        $response->assertSee('siddhiḥ');
        $response->assertSee('успех');
        // The stats block proves the whole feed was parsed, not just its header.
        $response->assertSee('125');
        $response->assertSee('900');
    }

    /** @test */
    public function the_cabinet_index_lists_the_cohort_packs(): void
    {
        $this->enableBothGates();

        $response = $this->actingAs($this->entitledUser())->get('/dvaram/reading');

        $response->assertOk();
        $response->assertSee('Hitopadeśa — Prastāvikā (opening)');
        $response->assertSee('/dvaram/reading/hitopadesa-0');
    }

    /** @test */
    public function a_logged_in_student_without_the_cohort_gets_404(): void
    {
        $this->enableBothGates();
        $this->cohortCourse();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get('/dvaram/reading/hitopadesa-0')->assertNotFound();
        $this->actingAs($stranger)->get('/dvaram/reading')->assertNotFound();
    }

    /** @test */
    public function the_cohort_flag_off_404s_even_for_a_paid_student(): void
    {
        config([
            'features.kosha_reader' => true,
            'features.start_chteniya_cohort' => false,
        ]);

        $user = $this->entitledUser();

        $this->actingAs($user)->get('/dvaram/reading/hitopadesa-0')->assertNotFound();
    }

    /** @test */
    public function the_reader_flag_off_404s_even_for_an_entitled_student(): void
    {
        config([
            'features.kosha_reader' => false,
            'features.start_chteniya_cohort' => true,
        ]);

        $user = $this->entitledUser();

        $this->actingAs($user)->get('/dvaram/reading/hitopadesa-0')->assertNotFound();
    }

    /** @test */
    public function an_unoffered_slug_404s_rather_than_resolving_a_path(): void
    {
        $this->enableBothGates();
        $user = $this->entitledUser();

        // A slug the controller does not offer must never reach the filesystem — including
        // one whose feed genuinely exists (nala-1 is a legacy public-demo pack, not cohort).
        $this->actingAs($user)->get('/dvaram/reading/nala-1')->assertNotFound();
        $this->actingAs($user)->get('/dvaram/reading/no-such-pack')->assertNotFound();
    }

    /** @test */
    public function the_public_demo_route_is_unchanged_and_still_needs_no_entitlement(): void
    {
        // The H2110 refactor moved the demo's markup into a shared partial; this pins that
        // the public route kept its old contract (flag-only, no login, nala-1).
        config([
            'features.kosha_reader' => true,
            'features.start_chteniya_cohort' => false,
        ]);

        $response = $this->get('/reading/kosha-demo');

        $response->assertOk();
        $response->assertSee('Nala 1');
        $response->assertSee('bṛhadaśva');
    }
}
