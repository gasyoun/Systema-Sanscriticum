<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\DealKanbanBoard;
use App\Models\Course;
use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Services\Crm\TrialBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrialBookingTest extends TestCase
{
    use RefreshDatabase;

    private TrialBookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->booking = app(TrialBookingService::class);
    }

    public function test_crm_trial_booking_defaults_to_false(): void
    {
        $this->assertFalse((bool) config('features.crm_trial_booking'));
        $this->assertFalse((bool) config('features.crm_trial_widget_public'));
    }

    public function test_flag_off_book_free_inserts_zero_deals(): void
    {
        [$schedule] = $this->introSchedule();

        $result = $this->booking->bookFree('guest@example.com', $schedule);

        $this->assertNull($result);
        $this->assertSame(0, Deal::query()->count());
    }

    public function test_flag_off_observer_does_not_set_kind_on_trial_payment(): void
    {
        config(['features.crm_pipeline_board' => true]);
        $course = Course::factory()->create(['is_active' => true]);

        Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, Deal::query()->where('kind', Deal::KIND_TRIAL)->count());
    }

    public function test_flag_off_pipeline_board_still_opens_course_deal(): void
    {
        config(['features.crm_pipeline_board' => true]);

        Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $deal = Deal::query()->sole();
        $this->assertSame(Deal::KIND_COURSE, $deal->kind);
    }

    public function test_free_book_creates_lead_and_deal_without_user_or_access(): void
    {
        config(['features.crm_trial_booking' => true]);
        [$schedule, $course] = $this->introSchedule();
        $groupsBefore = DB::table('course_group')->count();
        $usersBefore = DB::table('group_user')->count();
        $grantsBefore = LessonAccessGrant::query()->count();

        $deal = $this->booking->bookFree('guest@example.com', $schedule, ['name' => 'Гость']);

        $this->assertNotNull($deal);
        $this->assertSame(Deal::KIND_TRIAL, $deal->kind);
        $this->assertSame(Deal::TRIAL_SOURCE_FREE, $deal->trial_source);
        $this->assertSame(Deal::TRIAL_OUTCOME_BOOKED, $deal->trial_outcome);
        $this->assertSame($schedule->id, $deal->schedule_id);
        $this->assertSame($course->id, $deal->course_id);
        $this->assertNull($deal->user_id);
        $this->assertNotNull($deal->lead_id);
        $this->assertSame('guest@example.com', Lead::query()->find($deal->lead_id)?->email);
        $this->assertSame(0.0, (float) $deal->amount);
        $this->assertSame($groupsBefore, DB::table('course_group')->count());
        $this->assertSame($usersBefore, DB::table('group_user')->count());
        $this->assertSame($grantsBefore, LessonAccessGrant::query()->count());
    }

    public function test_free_book_sets_user_id_when_user_already_exists(): void
    {
        config(['features.crm_trial_booking' => true]);
        [$schedule] = $this->introSchedule();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $deal = $this->booking->bookFree('known@example.com', $schedule);

        $this->assertSame($user->id, $deal?->user_id);
    }

    public function test_second_book_free_same_email_and_schedule_is_idempotent(): void
    {
        config(['features.crm_trial_booking' => true]);
        [$schedule] = $this->introSchedule();

        $first = $this->booking->bookFree('guest@example.com', $schedule);
        $second = $this->booking->bookFree('guest@example.com', $schedule);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, Deal::query()->count());
        $this->assertSame(1, Lead::query()->where('email', 'guest@example.com')->count());
    }

    public function test_paid_trial_tags_the_same_deal(): void
    {
        config(['features.crm_trial_booking' => true]);
        [$schedule, $course] = $this->introSchedule();
        $user = User::factory()->create(['email' => 'payer@example.com']);
        $this->booking->bookFree('payer@example.com', $schedule);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertSame(1, Deal::query()->count());
        $deal = Deal::query()->sole();
        $this->assertSame(Deal::KIND_TRIAL, $deal->kind);
        $this->assertSame(Deal::TRIAL_SOURCE_PAID, $deal->trial_source);
        $this->assertSame(Deal::TRIAL_OUTCOME_BOOKED, $deal->trial_outcome);
        $this->assertSame($schedule->id, $deal->schedule_id);
    }

    public function test_paid_trial_without_prior_book_opens_one_deal(): void
    {
        config(['features.crm_trial_booking' => true]);
        [$schedule, $course] = $this->introSchedule();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertSame(1, Deal::query()->count());
        $deal = Deal::query()->sole();
        $this->assertSame(Deal::KIND_TRIAL, $deal->kind);
        $this->assertSame(Deal::TRIAL_SOURCE_PAID, $deal->trial_source);
        $this->assertSame($schedule->id, $deal->schedule_id);
    }

    public function test_zoom_match_marks_attended_and_opens_follow_up(): void
    {
        config(['features.crm_trial_booking' => true]);
        $deal = $this->pastTrialDeal('zoom@example.com');

        WebinarAttendance::create([
            'schedule_id' => $deal->schedule_id,
            'zoom_participant_uuid' => 'uuid-1',
            'email' => 'zoom@example.com',
            'name' => 'Guest',
            'joined_at' => now()->subHour(),
        ]);

        $this->artisan('crm:reconcile-trial-attendance')->assertSuccessful();

        $deal->refresh();
        $this->assertSame(Deal::TRIAL_OUTCOME_ATTENDED, $deal->trial_outcome);
        $task = FollowUpTask::query()->where('deal_id', $deal->id)->open()->sole();
        $this->assertSame(FollowUpTask::TYPE_MESSAGE, $task->type);
        $this->assertSame('дожим после пробника', $task->note);
        $this->assertTrue($task->due_at->isSameDay(now()->addDay()));
    }

    public function test_zoom_miss_leaves_booked_and_opens_confirm_task(): void
    {
        config(['features.crm_trial_booking' => true]);
        $deal = $this->pastTrialDeal('miss@example.com');

        $this->artisan('crm:reconcile-trial-attendance')->assertSuccessful();

        $deal->refresh();
        $this->assertSame(Deal::TRIAL_OUTCOME_BOOKED, $deal->trial_outcome);
        $task = FollowUpTask::query()->where('deal_id', $deal->id)->open()->sole();
        $this->assertSame('подтвердить посещение', $task->note);
    }

    public function test_staff_override_to_no_show_sticks(): void
    {
        config(['features.crm_trial_booking' => true]);
        $staff = User::factory()->create(['role' => 'admin']);
        $deal = $this->pastTrialDeal('staff@example.com');

        $this->booking->applyOutcome($deal, Deal::TRIAL_OUTCOME_NO_SHOW, $staff);

        $deal->refresh();
        $this->assertSame(Deal::TRIAL_OUTCOME_NO_SHOW, $deal->trial_outcome);

        $this->artisan('crm:reconcile-trial-attendance')->assertSuccessful();
        $deal->refresh();
        $this->assertSame(Deal::TRIAL_OUTCOME_NO_SHOW, $deal->trial_outcome);
    }

    public function test_kanban_title_badge_hidden_when_flag_off(): void
    {
        $deal = Deal::factory()->trialFree()->create();
        $this->assertStringNotContainsString('Пробник', $deal->kanban_title);

        config(['features.crm_trial_booking' => true]);
        $this->assertStringStartsWith('Пробник · ', $deal->fresh()->kanban_title);
        $this->assertFalse(DealKanbanBoard::canAccess());
    }

    public function test_service_never_writes_access_tables_on_free_book(): void
    {
        config(['features.crm_trial_booking' => true]);
        [$schedule] = $this->introSchedule();
        $grants = LessonAccessGrant::query()->count();
        $courseGroup = DB::table('course_group')->count();

        $this->booking->bookFree('fence@example.com', $schedule);
        $this->booking->bookFree('fence@example.com', $schedule);

        $this->assertSame($grants, LessonAccessGrant::query()->count());
        $this->assertSame($courseGroup, DB::table('course_group')->count());
        $this->assertSame(0, Payment::query()->count());
    }

    /** @return array{0: Schedule, 1: Course} */
    private function introSchedule(): array
    {
        $course = Course::factory()->create(['is_active' => true, 'slug' => 'trial-intro']);
        $schedule = Schedule::create([
            'title' => 'Пробное',
            'course_id' => $course->id,
            'start' => now()->addDays(2),
            'end' => now()->addDays(2)->addHours(2),
        ]);
        $course->update(['trial_schedule_id' => $schedule->id, 'trial_price' => 500]);

        return [$schedule, $course->fresh()];
    }

    private function pastTrialDeal(string $email): Deal
    {
        $course = Course::factory()->create(['is_active' => true]);
        $schedule = Schedule::create([
            'title' => 'Прошедшее пробное',
            'course_id' => $course->id,
            'start' => now()->subHours(3),
            'end' => now()->subHours(2),
        ]);
        $course->update(['trial_schedule_id' => $schedule->id]);

        $deal = $this->booking->bookFree($email, $schedule);
        $this->assertNotNull($deal);

        return $deal;
    }
}
