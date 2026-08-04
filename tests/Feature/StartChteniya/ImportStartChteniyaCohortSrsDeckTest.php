<?php

declare(strict_types=1);

namespace Tests\Feature\StartChteniya;

use App\Models\Course;
use App\Models\Payment;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2106 — cohort SRS import (`srs:import-start-chteniya-cohort`).
 *
 * Uses a small fixture TSV via --path rather than the real vendored
 * lemmas_for_srs.tsv (92 KB, kosha-owned data) — this test pins the import's
 * OWN behaviour (gating, private-deck-per-user, idempotent re-run), not the
 * freeze content, which scripts/vendor_cohort_start_chteniya_packs.py already
 * verifies by sha256 at vendor time.
 */
class ImportStartChteniyaCohortSrsDeckTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->fixturePath = tempnam(sys_get_temp_dir(), 'sc_srs_fixture_').'.tsv';
        file_put_contents($this->fixturePath, implode("\n", [
            "pack\tlemma_slp1\tsurface\tgloss_ru\tgloss_en\tlocus",
            "hitopadesa-0\tkatham\tkatham\tкак\thow\t1",
            "hitopadesa-0\trajan\trAjA\tцарь\tking\t2",
        ])."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
        parent::tearDown();
    }

    private function cohortCourse(): Course
    {
        $course = Course::factory()->create(['slug' => 'start-chteniya']);
        config(['start_chteniya.course_slug' => $course->slug]);

        return $course;
    }

    private function entitleUser(Course $course): User
    {
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $user;
    }

    /** @test */
    public function feature_flag_off_imports_nothing(): void
    {
        config(['features.start_chteniya_cohort' => false]);
        $course = $this->cohortCourse();
        $this->entitleUser($course);

        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $this->fixturePath])
            ->assertSuccessful();

        $this->assertSame(0, SrsDeck::query()->count());
    }

    /** @test */
    public function dry_run_writes_nothing(): void
    {
        config(['features.start_chteniya_cohort' => true]);
        $course = $this->cohortCourse();
        $this->entitleUser($course);

        $this->artisan('srs:import-start-chteniya-cohort', ['--dry-run' => true, '--path' => $this->fixturePath])
            ->assertSuccessful();

        $this->assertSame(0, SrsDeck::query()->count());
        $this->assertSame(0, SrsCard::query()->count());
    }

    /** @test */
    public function entitled_user_gets_a_private_deck_with_every_lemma(): void
    {
        config(['features.start_chteniya_cohort' => true]);
        $course = $this->cohortCourse();
        $user = $this->entitleUser($course);

        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $this->fixturePath])
            ->assertSuccessful();

        $deck = SrsDeck::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($deck);
        $this->assertSame('private', $deck->visibility);
        $this->assertSame(2, SrsCard::query()->where('deck_id', $deck->id)->count());
    }

    /** @test */
    public function a_non_entitled_user_gets_no_deck(): void
    {
        config(['features.start_chteniya_cohort' => true]);
        $this->cohortCourse();
        $user = User::factory()->create();

        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $this->fixturePath])
            ->assertSuccessful();

        $this->assertSame(0, SrsDeck::query()->where('user_id', $user->id)->count());
    }

    /** @test */
    public function a_deposit_only_payer_gets_no_deck(): void
    {
        // Fail = mirrors StartChteniyaCohort::hasEntitlement()'s own deposit/trial fence.
        config(['features.start_chteniya_cohort' => true]);
        $course = $this->cohortCourse();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $this->fixturePath])
            ->assertSuccessful();

        $this->assertSame(0, SrsDeck::query()->where('user_id', $user->id)->count());
    }

    /** @test */
    public function rerun_is_idempotent(): void
    {
        config(['features.start_chteniya_cohort' => true]);
        $course = $this->cohortCourse();
        $user = $this->entitleUser($course);

        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $this->fixturePath])->assertSuccessful();
        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $this->fixturePath])->assertSuccessful();

        $this->assertSame(1, SrsDeck::query()->where('user_id', $user->id)->count());

        $deck = SrsDeck::query()->where('user_id', $user->id)->first();
        $this->assertSame(2, SrsCard::query()->where('deck_id', $deck->id)->count());
    }

    /** @test */
    public function each_entitled_user_gets_their_own_deck_never_a_shared_one(): void
    {
        config(['features.start_chteniya_cohort' => true]);
        $course = $this->cohortCourse();
        $userA = $this->entitleUser($course);
        $userB = $this->entitleUser($course);

        $this->artisan('srs:import-start-chteniya-cohort', ['--path' => $this->fixturePath])
            ->assertSuccessful();

        $deckA = SrsDeck::query()->where('user_id', $userA->id)->first();
        $deckB = SrsDeck::query()->where('user_id', $userB->id)->first();

        $this->assertNotNull($deckA);
        $this->assertNotNull($deckB);
        $this->assertNotSame($deckA->id, $deckB->id);
        $this->assertSame(2, SrsCard::query()->where('deck_id', $deckA->id)->count());
        $this->assertSame(2, SrsCard::query()->where('deck_id', $deckB->id)->count());
    }
}
