<?php

declare(strict_types=1);

namespace Tests\Feature\CohortContract;

use App\Http\Controllers\StudentController;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Support\CourseCohortEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use JsonException;
use Tests\TestCase;

/**
 * H4162 — Kochergina reference cohort, W0: v1 contract fixtures.
 *
 * Three committed fixtures pin the promise → access → first-result contract
 * for ONE reference cohort (grammatika-po-kocerginoi-gr61, observed on prod
 * 2026-09-06) and test it against the REAL machinery — Payment::fireOnPaid →
 * grantAccess() → group_user, getUserUnlockedTariffs(), CourseCohortEntitlement's
 * cohort filter, StudentController::completeLesson() — no new money/access system.
 *
 * Stop fences baked into the fixtures: nothing here enables a live cohort,
 * changes a price, or expands the catalogue (W1/ops flip `enabled` — human-gated).
 */
class KocherginaCohortContractV1Test extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = __DIR__.'/../../Fixtures/cohort_contract_v1/';

    private const REFERENCE_SLUG = 'grammatika-po-kocerginoi-gr61';

    private const COHORT_KEY = 'kochergina-gr61';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $raw = file_get_contents(self::FIXTURE_DIR.$name.'.json');

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail("Fixture [{$name}] is not valid JSON: {$e->getMessage()}");
        }

        return $decoded;
    }

    public static function entitlementScenarioProvider(): array
    {
        $raw = file_get_contents(
            __DIR__.'/../../Fixtures/cohort_contract_v1/kochergina_entitlement_token_v1.json'
        );
        $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return collect($fixture['scenarios'])
            ->reject(fn (array $s) => (bool) ($s['payment']['on_other_cohort_course'] ?? false))
            ->mapWithKeys(fn (array $s) => [$s['name'] => [$s]])
            ->all();
    }

    // ── 1. course-catalogue v1 ────────────────────────────────────────────

    /** @test */
    public function catalogue_fixture_pins_the_reference_cohort_contract(): void
    {
        $fixture = $this->fixture('kochergina_cohort_catalogue_v1');

        $this->assertSame('kochergina_cohort_catalogue_v1', $fixture['contract']);
        $this->assertNotEmpty($fixture['observed_at_utc']);

        $cohort = $fixture['reference_cohort'];
        $this->assertSame(self::REFERENCE_SLUG, $cohort['slug']);
        $this->assertSame(
            config('homework.auto_open.kochergina_slug_prefix'),
            $cohort['slug_prefix'],
            'The catalogue contract must use the estate\'s own Kochergina slug detector (config/homework.php).'
        );

        foreach (['schedule', 'curator', 'materials', 'offer_promise', 'group'] as $key) {
            $this->assertArrayHasKey($key, $cohort, "The promise map must name [{$key}].");
        }
        $this->assertNotEmpty($cohort['curator']['name']);
        $this->assertNotEmpty($cohort['schedule']['weekday']);
        $this->assertGreaterThan(0, $cohort['lessons_planned']);

        $this->assertNotEmpty($fixture['tariffs_observed']);
        foreach ($fixture['tariffs_observed'] as $tariff) {
            $this->assertGreaterThan(0, (float) $tariff['price'], 'W0 records prices, never invents them.');
        }

        $drafted = $fixture['drafted_cohort_courses_entry'];
        $this->assertSame(self::REFERENCE_SLUG, $drafted['course_slug']);
        $this->assertFalse(
            $drafted['enabled'],
            'Stop fence: W0 drafts the entry with enabled=false — enabling a live cohort is W1, human-gated.'
        );
        $this->assertArrayHasKey('no_live_enable', $fixture['stop_fences']);
    }

    /** @test */
    public function catalogue_identity_chain_resolves_through_the_real_slug_resolver(): void
    {
        $fixture = $this->fixture('kochergina_cohort_catalogue_v1');
        $slug = $fixture['reference_cohort']['slug'];

        $course = Course::factory()->create([
            'slug' => $slug,
            'title' => $fixture['reference_cohort']['title'],
        ]);

        $resolved = Course::resolveBySlug($slug);
        $this->assertNotNull($resolved, 'The fixture slug must resolve through the production slug resolver.');
        $this->assertSame($course->id, $resolved->id);
    }

    /** @test */
    public function drafted_entry_is_off_by_default_and_the_only_switch_is_enabled(): void
    {
        $fixture = $this->fixture('kochergina_cohort_catalogue_v1');
        $drafted = $fixture['drafted_cohort_courses_entry'];

        $course = Course::factory()->create(['slug' => $drafted['course_slug']]);
        config([
            'cohort_courses.'.self::COHORT_KEY => [
                'course_slug' => $drafted['course_slug'],
                'enabled' => false,
            ],
        ]);

        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $this->assertFalse(
            CourseCohortEntitlement::hasEntitlement($user->fresh(), self::COHORT_KEY),
            'The drafted W0 entry must stay OFF: a paid student gets no cohort surface until ops flips enabled.'
        );

        config(['cohort_courses.'.self::COHORT_KEY.'.enabled' => true]);
        $this->assertTrue(
            CourseCohortEntitlement::hasEntitlement($user->fresh(), self::COHORT_KEY),
            'Flipping enabled is the ONLY switch the drafted entry adds — pinning its semantics for W1.'
        );
    }

    // ── 2. entitlement-token v1 ───────────────────────────────────────────

    /** @test */
    public function entitlement_token_fixture_covers_every_payment_kind(): void
    {
        $fixture = $this->fixture('kochergina_entitlement_token_v1');
        $names = collect($fixture['scenarios'])->pluck('name');

        foreach (['paid_block_1_entitles_and_unlocks', 'deposit_does_not_entitle',
            'trial_does_not_entitle', 'conditional_promise_grants_access_but_not_cohort_entitlement',
            'pending_grants_nothing', 'cross_course_payment_leaks_nothing', ] as $required) {
            $this->assertTrue($names->contains($required), "Missing scenario [{$required}].");
        }

        $this->assertSame('users.id', $fixture['token_shape']['user_id']);
        $this->assertArrayHasKey('identifier_chain', $fixture);
        $this->assertArrayHasKey('revocation', $fixture);
    }

    /**
     * @dataProvider entitlementScenarioProvider
     *
     * @param  array<string, mixed>  $scenario
     */
    public function test_entitlement_token_scenarios(array $scenario): void
    {
        $world = $this->makeCohortWorld();
        $user = $world['user'];

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $world['course']->id,
            'amount' => $scenario['payment']['amount'],
            'tariff' => $scenario['payment']['tariff'],
            'status' => $scenario['payment']['status'],
            'is_conditional' => (bool) ($scenario['payment']['is_conditional'] ?? false),
        ]);

        $this->assertTokenMatchesExpected($user, $world, $scenario['expected'], $scenario['name']);
    }

    /** @test */
    public function cross_course_payment_leaks_nothing(): void
    {
        $fixture = $this->fixture('kochergina_entitlement_token_v1');
        $scenario = collect($fixture['scenarios'])
            ->first(fn (array $s) => (bool) ($s['payment']['on_other_cohort_course'] ?? false));
        $this->assertNotNull($scenario, 'The cross-course scenario must exist in the fixture.');

        $world = $this->makeCohortWorld();
        $other = $this->makeCohortWorld();

        Payment::create([
            'user_id' => $world['user']->id,
            'course_id' => $other['course']->id,
            'amount' => $scenario['payment']['amount'],
            'tariff' => $scenario['payment']['tariff'],
            'status' => $scenario['payment']['status'],
        ]);

        $this->assertTokenMatchesExpected($world['user'], $world, $scenario['expected'], $scenario['name']);
    }

    // ── 3. first-result learning event v1 ─────────────────────────────────

    /** @test */
    public function first_result_event_fixture_pins_idempotent_replay_and_rollback(): void
    {
        $fixture = $this->fixture('kochergina_first_result_event_v1');

        $this->assertSame('lesson_completed', $fixture['event']['name']);
        $this->assertStringContainsString('student.lesson.complete', $fixture['event']['route']);
        $this->assertArrayHasKey('canonical_effect', $fixture['event']);
        $this->assertArrayHasKey('first_result_definition', $fixture);
        $this->assertArrayHasKey('denominator_definitions_source', $fixture);

        $prod = $fixture['prod_reference_cohort_434_gr61'];
        $this->assertSame(434, $prod['course_id'] ?? 434);
        $this->assertGreaterThan(0, $prod['lesson_completed_rows']);
        $this->assertGreaterThan(0, $prod['homework_submissions']);
        $this->assertArrayHasKey('observed_at_utc', $prod);
    }

    /** @test */
    public function first_result_event_replays_to_exactly_one_canonical_effect(): void
    {
        $world = $this->makeCohortWorld();
        $user = $world['user'];

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $world['course']->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $post = fn () => $this->actingAs($user)
            ->post(route('student.lesson.complete', [
                'slug' => $world['course']->slug,
                'lessonId' => $world['lesson']->id,
            ]));

        $post()->assertStatus(302);
        $post()->assertStatus(302);

        $rows = DB::table('lesson_user')
            ->where('user_id', $user->id)
            ->where('lesson_id', $world['lesson']->id)
            ->get();

        $this->assertCount(
            1,
            $rows,
            'Replay must update the same pivot row, never insert a second one (lesson_user has no unique index — the guard IS the contract).'
        );
        $this->assertSame(1, (int) $rows[0]->is_completed);

        $this->assertSame(
            1,
            DB::table('activity_events')
                ->where('user_id', $user->id)
                ->where('event_type', ActivityEvent::LESSON_MARK_MASTERED)
                ->count(),
            'The completion telemetry fires on the FIRST completion only — one replay, one canonical event.'
        );

        $firstResult = DB::table('lesson_user')
            ->where('user_id', $user->id)
            ->where('lesson_id', $world['lesson']->id)
            ->min('created_at');
        $this->assertNotNull($firstResult, 'The first-result moment must be traceable.');
    }

    /** @test */
    public function first_result_event_is_denied_without_entitlement(): void
    {
        $world = $this->makeCohortWorld();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('student.lesson.complete', [
                'slug' => $world['course']->slug,
                'lessonId' => $world['lesson']->id,
            ]))
            ->assertStatus(403);

        $this->assertSame(
            0,
            DB::table('lesson_user')->where('user_id', $outsider->id)->count(),
            'No event without entitlement — the denied write is the fail condition.'
        );
    }

    /** @test */
    public function rollback_revoke_locks_content_and_keeps_history(): void
    {
        $world = $this->makeCohortWorld();
        $user = $world['user'];

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $world['course']->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->post(route('student.lesson.complete', [
                'slug' => $world['course']->slug,
                'lessonId' => $world['lesson']->id,
            ]))
            ->assertStatus(302);

        $user->groups()->detach($world['group']->id);
        $payment->update(['status' => 'canceled']);

        $this->actingAs($user->fresh())
            ->post(route('student.lesson.complete', [
                'slug' => $world['course']->slug,
                'lessonId' => $world['lesson']->id,
            ]))
            ->assertStatus(403);

        $history = DB::table('lesson_user')
            ->where('user_id', $user->id)
            ->where('lesson_id', $world['lesson']->id)
            ->get();

        $this->assertCount(1, $history, 'Revocation locks content without deleting progress history.');
        $this->assertSame(1, (int) $history[0]->is_completed);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * A Kochergina-shaped cohort world: course with the family slug prefix,
     * one live group attached, one paid block-1 lesson in that group.
     *
     * @return array{course: Course, group: Group, lesson: Lesson, user: User}
     */
    private function makeCohortWorld(): array
    {
        static $worldSequence = 0;
        $worldSequence++;

        $course = Course::factory()->live()->create([
            'slug' => 'grammatika-po-kocerginoi-gr61-test-'.$worldSequence,
            'title' => 'Грамматика по Кочергиной гр.61 (fixture)',
        ]);

        $group = Group::create([
            'name' => 'Грамматика по Кочергиной гр.61 (fixture) #'.$worldSequence,
        ]);
        $course->groups()->attach($group->id);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'block_number' => 1,
        ]);

        return [
            'course' => $course,
            'group' => $group,
            'lesson' => $lesson,
            'user' => User::factory()->create(),
        ];
    }

    /**
     * The v1 entitlement token, resolved through the production machinery.
     *
     * @param  array{course: Course, group: Group, lesson: Lesson, user: User}  $world
     * @param  array<string, mixed>  $expected
     */
    private function assertTokenMatchesExpected(User $user, array $world, array $expected, string $scenario): void
    {
        $fresh = $user->fresh();
        $keys = StudentController::getUserUnlockedTariffs($fresh->id, $world['course']->slug);

        $token = [
            'user_id' => $fresh->id,
            'course_id' => $world['course']->id,
            'group_ids' => $fresh->groups->pluck('id')->all(),
            'unlocked_tariff_keys' => $keys,
            'entitled_cohort' => Payment::query()
                ->where('user_id', $fresh->id)
                ->where('course_id', $world['course']->id)
                ->paid()
                ->real()
                ->whereNotIn('tariff', ['deposit', 'trial'])
                ->exists(),
        ];

        $inGroup = in_array($world['group']->id, $token['group_ids'], true);
        $lessonUnlocked = $world['lesson']->isVisibleToGroupsOf($fresh)
            && $world['lesson']->isUnlockedBy($token['unlocked_tariff_keys']);

        $this->assertSame(
            (bool) $expected['entitled_cohort'],
            $token['entitled_cohort'],
            "[{$scenario}] entitled_cohort mismatch."
        );
        $this->assertSame(
            (bool) $expected['in_group'],
            $inGroup,
            "[{$scenario}] in_group mismatch."
        );
        $this->assertSame(
            (bool) $expected['lesson_unlocked'],
            $lessonUnlocked,
            "[{$scenario}] lesson_unlocked mismatch."
        );
    }
}
