<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Segment;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Services\DebtorsReport;
use App\Services\ReactivationReport;
use App\Services\StuckStudentsReport;
use Database\Seeders\SegmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GC-A1 segment engine (H1637). Covers: (a) each built-in segment matches its
 * underlying report's own output exactly, (b) custom criteria evaluate
 * correctly and AND-combine, (c) the money-core boundary rule — evaluating
 * ANY segment must never write to ranks 1-5
 * (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2).
 */
class SegmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deploy-рубильник: evaluation is a no-op with the flag OFF (default),
        // so every test that actually evaluates a segment turns it on.
        config(['features.marketing_segments' => true]);

        // Seeders don't auto-run under RefreshDatabase — the three built-in
        // segments are seeded infrastructure (SegmentSeeder), not a migration.
        $this->seed(SegmentSeeder::class);
    }

    private function makeCourseWithCurrentBlock(): Course
    {
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 1]);

        return $course;
    }

    /** «Должник без оплат»: в группе курса, реального платежа нет. */
    private function makeNoPaymentDebtor(Course $course, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $group = Group::create(['name' => 'Поток '.fake()->unique()->numberBetween(1, 999999)]);
        $course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        return $user;
    }

    // ---------------------------------------------------------------
    // (a) Built-in segments match their wrapped report exactly.
    // ---------------------------------------------------------------

    /** @test */
    public function builtin_reactivation_segment_matches_reactivation_report_exactly(): void
    {
        $course = $this->makeCourseWithCurrentBlock();
        $debtor = $this->makeNoPaymentDebtor($course);
        User::factory()->create(); // noise: unrelated user, must not appear

        $segment = Segment::query()->where('builtin_key', Segment::BUILTIN_REACTIVATION)->firstOrFail();

        $expectedIds = app(ReactivationReport::class)->query()->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $actualIds = $segment->matchingStudents()->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $this->assertNotEmpty($expectedIds);
        $this->assertSame($expectedIds->all(), $actualIds->all());
        $this->assertTrue($actualIds->contains($debtor->id));
    }

    /** @test */
    public function builtin_debtors_segment_matches_debtors_report_exactly(): void
    {
        $course = $this->makeCourseWithCurrentBlock();
        $debtor = $this->makeNoPaymentDebtor($course);
        User::factory()->create(); // noise

        $segment = Segment::query()->where('builtin_key', Segment::BUILTIN_DEBTORS)->firstOrFail();

        $expectedIds = app(DebtorsReport::class)->query()->pluck('users.id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $actualIds = $segment->matchingStudents()->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $this->assertNotEmpty($expectedIds);
        $this->assertSame($expectedIds->all(), $actualIds->all());
        $this->assertTrue($actualIds->contains($debtor->id));
    }

    /** @test */
    public function builtin_stuck_students_segment_matches_stuck_students_report_exactly(): void
    {
        $stuck = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(20),
        ]);
        $active = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subHours(2),
        ]);

        $segment = Segment::query()->where('builtin_key', Segment::BUILTIN_STUCK_STUDENTS)->firstOrFail();

        $expectedIds = StuckStudentsReport::query()->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $actualIds = $segment->matchingStudents()->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $this->assertSame($expectedIds->all(), $actualIds->all());
        $this->assertTrue($actualIds->contains($stuck->id));
        $this->assertFalse($actualIds->contains($active->id));
    }

    // ---------------------------------------------------------------
    // (b) Custom criteria segments evaluate correctly.
    // ---------------------------------------------------------------

    /** @test */
    public function custom_segment_matches_utm_source_criterion(): void
    {
        $vk = User::factory()->create(['utm_source' => 'vk']);
        $other = User::factory()->create(['utm_source' => 'telegram']);

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'utm_source', 'value' => 'vk']],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($vk->id));
        $this->assertFalse($ids->contains($other->id));
    }

    /** @test */
    public function custom_segment_matches_group_criterion(): void
    {
        $group = Group::create(['name' => 'Санскрит-1']);
        $member = User::factory()->create();
        $member->groups()->attach($group->id);
        $nonMember = User::factory()->create();

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'group', 'group_id' => $group->id]],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($member->id));
        $this->assertFalse($ids->contains($nonMember->id));
    }

    /** @test */
    public function custom_segment_matches_completed_lesson_criterion(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();

        $completer = User::factory()->create();
        $completer->completedLessons()->attach($lesson->id, ['is_completed' => true]);
        $nonCompleter = User::factory()->create();

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'completed_lesson', 'lesson_id' => $lesson->id]],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($completer->id));
        $this->assertFalse($ids->contains($nonCompleter->id));
    }

    /** @test */
    public function custom_segment_matches_tariff_owned_criterion(): void
    {
        $course = Course::factory()->create();
        $owner = User::factory()->create();
        Payment::withoutEvents(function () use ($owner, $course): void {
            Payment::create([
                'user_id' => $owner->id,
                'course_id' => $course->id,
                'tariff' => 'full',
                'status' => 'paid',
                'amount' => 10000,
                'is_conditional' => false,
            ]);
        });
        $nonOwner = User::factory()->create();

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'tariff_owned', 'course_id' => $course->id, 'tariff' => 'full']],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($owner->id));
        $this->assertFalse($ids->contains($nonOwner->id));
    }

    /** @test */
    public function custom_segment_matches_lead_status_criterion_by_email(): void
    {
        $stage = DB::table('lead_stages')->first();
        $this->assertNotNull($stage, 'lead_stages must be seeded for this test to be meaningful');

        $matched = User::factory()->create(['email' => 'lead-match@example.org']);
        Lead::query()->create([
            'name' => 'Lead Match',
            'contact' => 'lead-match@example.org',
            'email' => 'lead-match@example.org',
            'status' => $stage->key,
        ]);
        $unmatched = User::factory()->create(['email' => 'no-lead@example.org']);

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'lead_status', 'status' => $stage->key]],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($matched->id));
        $this->assertFalse($ids->contains($unmatched->id));
    }

    /** @test */
    public function custom_segment_matches_last_activity_days_criterion(): void
    {
        $inactive = User::factory()->create(['last_activity_at' => now()->subDays(30)]);
        $active = User::factory()->create(['last_activity_at' => now()->subDays(2)]);

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'last_activity_days', 'min_days' => 14]],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($active->id));
    }

    /** @test */
    public function custom_segment_matches_debtor_criterion(): void
    {
        $course = $this->makeCourseWithCurrentBlock();
        $debtor = $this->makeNoPaymentDebtor($course);
        $notDebtor = User::factory()->create();

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'debtor']],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($debtor->id));
        $this->assertFalse($ids->contains($notDebtor->id));
    }

    /** @test */
    public function custom_segment_matches_attendance_below_criterion(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Посещаемость']);

        $lowAttendance = User::factory()->create();
        $lowAttendance->groups()->attach($group->id);
        $fullAttendance = User::factory()->create();
        $fullAttendance->groups()->attach($group->id);

        $schedule1 = Schedule::create(['title' => 'З1', 'start' => now()->subDays(5), 'group_id' => $group->id, 'course_id' => $course->id]);
        $schedule2 = Schedule::create(['title' => 'З2', 'start' => now()->subDays(2), 'group_id' => $group->id, 'course_id' => $course->id]);

        // low: 1 of 2 expected (0.5). full: 2 of 2 expected (1.0).
        WebinarAttendance::create(['schedule_id' => $schedule1->id, 'user_id' => $lowAttendance->id, 'zoom_participant_uuid' => 'a1']);
        WebinarAttendance::create(['schedule_id' => $schedule1->id, 'user_id' => $fullAttendance->id, 'zoom_participant_uuid' => 'a2']);
        WebinarAttendance::create(['schedule_id' => $schedule2->id, 'user_id' => $fullAttendance->id, 'zoom_participant_uuid' => 'a3']);

        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'attendance_below', 'course_id' => $course->id, 'rate' => 0.6]],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($lowAttendance->id));
        $this->assertFalse($ids->contains($fullAttendance->id));
    }

    /** @test */
    public function custom_segment_combines_criteria_with_and(): void
    {
        $group = Group::create(['name' => 'AND-группа']);

        $matchesBoth = User::factory()->create(['utm_source' => 'vk']);
        $matchesBoth->groups()->attach($group->id);

        $matchesGroupOnly = User::factory()->create(['utm_source' => 'telegram']);
        $matchesGroupOnly->groups()->attach($group->id);

        $matchesUtmOnly = User::factory()->create(['utm_source' => 'vk']);

        $segment = Segment::factory()->create([
            'criteria' => [
                ['type' => 'group', 'group_id' => $group->id],
                ['type' => 'utm_source', 'value' => 'vk'],
            ],
        ]);

        $ids = $segment->matchingStudents()->pluck('id');

        $this->assertTrue($ids->contains($matchesBoth->id));
        $this->assertFalse($ids->contains($matchesGroupOnly->id));
        $this->assertFalse($ids->contains($matchesUtmOnly->id));
    }

    /** @test */
    public function segment_evaluation_returns_empty_when_flag_disabled(): void
    {
        config(['features.marketing_segments' => false]);

        User::factory()->create(['utm_source' => 'vk']);
        $segment = Segment::factory()->create([
            'criteria' => [['type' => 'utm_source', 'value' => 'vk']],
        ]);

        $this->assertCount(0, $segment->matchingStudents());

        $builtin = Segment::query()->where('builtin_key', Segment::BUILTIN_STUCK_STUDENTS)->firstOrFail();
        $this->assertCount(0, $builtin->matchingStudents());
    }

    // ---------------------------------------------------------------
    // (c) Money-core boundary rule: evaluation never writes to ranks 1-5.
    // ---------------------------------------------------------------

    /**
     * Modeled on WebinarProviderSeamTest::test_zoom_create_meeting_stays_removed_per_gc_b1
     * — asserts the ABSENCE of a write capability, at runtime, not by reading
     * the source. Two independent guards:
     *   1. every SQL statement issued while evaluating segments is a SELECT;
     *   2. row counts of every rank 1-5 table are byte-identical before/after.
     * Either guard alone would fail if a future edit added an
     * UPDATE/INSERT/DELETE anywhere on the evaluation path.
     */
    public function test_segment_evaluation_never_writes_to_ranks_1_5_tables(): void
    {
        $course = $this->makeCourseWithCurrentBlock();
        $this->makeNoPaymentDebtor($course);
        $lesson = Lesson::factory()->for($course)->create();
        $student = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(20),
            'utm_source' => 'vk',
        ]);
        $student->completedLessons()->attach($lesson->id, ['is_completed' => true]);
        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
            'reviewed_at' => now()->subDays(10),
            'last_activity_at' => now()->subDays(10),
        ]);
        $stage = DB::table('lead_stages')->first();
        Lead::query()->create(['name' => 'L', 'contact' => 'boundary@example.org', 'email' => 'boundary@example.org', 'status' => $stage->key]);

        $rankTables = ['payments', 'users', 'leads', 'course_user', 'lesson_user', 'group_user', 'webinar_attendances', 'schedules'];
        $countsBefore = collect($rankTables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()]);

        $writeVerbs = [];
        DB::listen(function ($query) use (&$writeVerbs): void {
            $verb = strtoupper(trim(explode(' ', ltrim($query->sql))[0] ?? ''));
            if (! in_array($verb, ['SELECT', 'PRAGMA'], true)) {
                $writeVerbs[] = $verb.': '.$query->sql;
            }
        });

        foreach (Segment::all() as $segment) {
            $segment->matchingStudents();
        }

        $custom = Segment::factory()->create([
            'criteria' => [
                ['type' => 'group', 'group_id' => 999999],
                ['type' => 'last_activity_days', 'min_days' => 10],
                ['type' => 'completed_lesson', 'lesson_id' => $lesson->id],
                ['type' => 'tariff_owned', 'course_id' => $course->id, 'tariff' => 'full'],
                ['type' => 'attendance_below', 'course_id' => $course->id, 'rate' => 0.5],
                ['type' => 'lead_status', 'status' => $stage->key],
                ['type' => 'debtor'],
                ['type' => 'utm_source', 'value' => 'vk'],
            ],
        ]);
        $writeVerbs = []; // creating the fixture segment itself legitimately INSERTs; only evaluation must be write-free
        $custom->matchingStudents();

        $this->assertSame([], $writeVerbs, 'segment evaluation issued a non-SELECT statement: '.implode(' | ', $writeVerbs));

        $countsAfter = collect($rankTables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()]);
        $this->assertSame($countsBefore->all(), $countsAfter->all(), 'segment evaluation changed row counts on a rank 1-5 table');
    }
}
