<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5442 wave observer — access is a lesson-level fact, not a group-membership
 * fact, and a promise grant is never skipped (independent review, 26-10-2026).
 *
 * Runs the real ops script, so the assertions cover the JSON the operator reads
 * on prod, not a copy of its logic.
 */
class WaveObserverAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Inside the observer's window (2026-09-24 16:22:51 → now).
        Carbon::setTestNow('2026-10-01 12:00:00');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function observe(): array
    {
        ob_start();
        include base_path('scripts/ops/h5442_wave_observe.php');
        $raw = (string) ob_get_clean();

        $data = json_decode($raw, true);
        $this->assertIsArray($data, 'the observer did not emit JSON: '.$raw);

        return $data;
    }

    private function paidRow(User $user, Course $course, string $tariff, float $amount = 4800.0, ?int $promiseId = null): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => $tariff,
            'status' => 'paid',
            'transaction_id' => $promiseId === null ? null : 'promise_grant_#'.$promiseId,
            'is_conditional' => $promiseId !== null,
            'linked_promise_id' => $promiseId,
            'first_paid_at' => now(),
        ]));
    }

    public function test_ungrouped_lessons_are_real_access_even_without_course_group(): void
    {
        $course = Course::factory()->create();
        $course->groups()->attach(Group::factory()->create()->id);
        $student = User::factory()->create();
        Lesson::factory()->count(2)->create([
            'course_id' => $course->id,
            'group_id' => null,
            'block_number' => 1,
        ]);
        $payment = $this->paidRow($student, $course, 'block_1');

        $access = $this->observe()['access'];

        $this->assertNotContains($payment->id, $access['paid_without_group_membership']);
        $this->assertNotContains($payment->id, $access['paid_on_groupless_course']);
        $this->assertSame([], $access['classified']['partially_delivered']);
    }

    public function test_promise_grant_is_classified_not_skipped_when_nothing_is_open(): void
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $student = User::factory()->create();
        Lesson::factory()->count(2)->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'block_number' => 1,
        ]);
        $promise = PaymentPromise::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'promised_at' => '2026-10-31',
            'amount' => 4800,
            'status' => 'active',
        ]);
        $payment = $this->paidRow($student, $course, 'block_1', 0.0, $promise->id);

        $access = $this->observe()['access'];

        $this->assertContains($payment->id, $access['paid_without_group_membership']);

        $grant = collect($access['promise_grants'])->firstWhere('payment_id', $payment->id);
        $this->assertNotNull($grant, 'a promise grant must always be reported');
        $this->assertSame(0, $grant['open_paid_lessons']);
        $this->assertSame(2, $grant['paid_lessons_in_scope']);
        $this->assertSame('active', $grant['promise_status']);
    }

    public function test_promise_grant_with_open_lessons_counts_as_delivered(): void
    {
        $course = Course::factory()->create();
        $course->groups()->attach(Group::factory()->create()->id);
        $student = User::factory()->create();
        Lesson::factory()->count(2)->create([
            'course_id' => $course->id,
            'group_id' => null,
            'block_number' => 1,
        ]);
        $promise = PaymentPromise::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'promised_at' => '2026-10-31',
            'amount' => 4800,
            'status' => 'active',
        ]);
        $payment = $this->paidRow($student, $course, 'block_1', 0.0, $promise->id);

        $access = $this->observe()['access'];

        $this->assertNotContains($payment->id, $access['paid_without_group_membership']);
        $grant = collect($access['promise_grants'])->firstWhere('payment_id', $payment->id);
        $this->assertNotNull($grant);
        $this->assertSame(2, $grant['open_paid_lessons']);
    }

    public function test_one_lesson_grant_does_not_deliver_a_whole_block(): void
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $student = User::factory()->create();
        $lessons = Lesson::factory()->count(3)->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'block_number' => 1,
        ]);
        LessonAccessGrant::create([
            'user_id' => $student->id,
            'lesson_id' => $lessons->first()->id,
            'course_id' => $course->id,
            'granted_at' => now(),
        ]);
        $payment = $this->paidRow($student, $course, 'block_1');

        $access = $this->observe()['access'];

        $this->assertNotContains($payment->id, $access['paid_without_group_membership']);
        $partial = collect($access['classified']['partially_delivered'])->firstWhere('payment_id', $payment->id);
        $this->assertNotNull($partial, 'a single granted lesson must be reported as partial delivery');
        $this->assertSame(1, $partial['open']);
        $this->assertSame(3, $partial['scope']);
    }

    public function test_log_coverage_is_reported_next_to_the_counts(): void
    {
        $coverage = $this->observe()['log_coverage'];

        $this->assertSame($coverage['files_present'] + count($coverage['files_missing']), $coverage['days']);
        $this->assertSame($coverage['files_missing'] === [], $coverage['complete']);
    }
}
