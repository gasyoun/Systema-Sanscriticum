<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Course;
use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Observers\PaymentDealBridgeObserver;
use App\Services\Crm\TrialBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H3247 cluster 1 — trial Deal, bookFree, paid-trial tag, Zoom reconcile.
 * Rank 4 fence: free book must not grant course access.
 */
class TrialBookingTest extends TestCase
{
    use RefreshDatabase;

    private function bookings(): TrialBookingService
    {
        return app(TrialBookingService::class);
    }

    private function introSchedule(): Schedule
    {
        $course = Course::factory()->create(['is_active' => true]);
        $schedule = Schedule::create([
            'title' => 'Вводное',
            'course_id' => $course->id,
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHours(2),
        ]);
        $course->update(['trial_schedule_id' => $schedule->id]);

        return $schedule->fresh();
    }

    /** @test */
    public function trial_booking_flag_defaults_off(): void
    {
        $this->assertFalse(config('features.crm_trial_booking'));
        $this->assertFalse(config('features.crm_trial_widget_public'));
    }

    /** @test */
    public function book_free_noops_when_flag_off(): void
    {
        config(['features.crm_trial_booking' => false]);
        $schedule = $this->introSchedule();

        $result = $this->bookings()->bookFree('guest@example.test', $schedule);

        $this->assertNull($result);
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, Lead::query()->count());
    }

    /** @test */
    public function observer_does_not_set_kind_when_trial_flag_off(): void
    {
        config([
            'features.crm_pipeline_board' => true,
            'features.crm_trial_booking' => false,
        ]);

        $payment = Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertTrue($payment->isTrial());
        $this->assertSame(0, Deal::query()->count());
    }

    /** @test */
    public function free_book_creates_lead_and_deal_without_user_or_access(): void
    {
        config(['features.crm_trial_booking' => true]);
        $schedule = $this->introSchedule();
        $courseGroups = DB::table('course_group')->count();
        $groupUsers = DB::table('group_user')->count();
        $grants = LessonAccessGrant::query()->count();

        $deal = $this->bookings()->bookFree('guest@example.test', $schedule, ['name' => 'Гость']);

        $this->assertNotNull($deal);
        $this->assertSame(Deal::KIND_TRIAL, $deal->kind);
        $this->assertSame(Deal::TRIAL_SOURCE_FREE, $deal->trial_source);
        $this->assertSame(Deal::TRIAL_OUTCOME_BOOKED, $deal->trial_outcome);
        $this->assertSame($schedule->id, $deal->schedule_id);
        $this->assertNull($deal->user_id);
        $this->assertNotNull($deal->lead_id);
        $this->assertSame('guest@example.test', $deal->lead->email);
        $this->assertSame($courseGroups, DB::table('course_group')->count());
        $this->assertSame($groupUsers, DB::table('group_user')->count());
        $this->assertSame($grants, LessonAccessGrant::query()->count());
    }

    /** @test */
    public function free_book_attaches_existing_user_id_but_still_no_access(): void
    {
        config(['features.crm_trial_booking' => true]);
        $user = User::factory()->create(['email' => 'known@example.test']);
        $schedule = $this->introSchedule();
        $grants = LessonAccessGrant::query()->count();

        $deal = $this->bookings()->bookFree('known@example.test', $schedule);

        $this->assertSame($user->id, $deal->user_id);
        $this->assertSame($grants, LessonAccessGrant::query()->count());
        $this->assertSame(0, DB::table('group_user')->where('user_id', $user->id)->count());
    }

    /** @test */
    public function second_book_free_same_email_and_schedule_is_idempotent(): void
    {
        config(['features.crm_trial_booking' => true]);
        $schedule = $this->introSchedule();

        $first = $this->bookings()->bookFree('guest@example.test', $schedule);
        $second = $this->bookings()->bookFree('guest@example.test', $schedule);

        $this->assertSame(1, Deal::query()->count());
        $this->assertTrue($first->is($second));
        $this->assertSame(1, Lead::query()->where('email', 'guest@example.test')->count());
    }

    /** @test */
    public function paid_trial_tags_the_same_deal_count_stays_one(): void
    {
        config([
            'features.crm_pipeline_board' => true,
            'features.crm_trial_booking' => true,
        ]);

        $user = User::factory()->create(['email' => 'payer@example.test']);
        $schedule = $this->introSchedule();
        $course = $schedule->course;

        $open = $this->bookings()->bookFree('payer@example.test', $schedule);
        $this->assertSame(1, Deal::query()->count());
        $this->assertSame(Deal::TRIAL_SOURCE_FREE, $open->trial_source);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertSame(1, Deal::query()->count());
        $open->refresh();
        $this->assertSame(Deal::KIND_TRIAL, $open->kind);
        $this->assertSame(Deal::TRIAL_SOURCE_PAID, $open->trial_source);
        $this->assertSame(Deal::TRIAL_OUTCOME_BOOKED, $open->trial_outcome);
        $this->assertNull($open->closed_at);
    }

    /** @test */
    public function paid_trial_without_prior_deal_opens_exactly_one(): void
    {
        config([
            'features.crm_pipeline_board' => true,
            'features.crm_trial_booking' => true,
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertSame(1, Deal::query()->count());
        $deal = Deal::query()->first();
        $this->assertSame(Deal::KIND_TRIAL, $deal->kind);
        $this->assertSame(Deal::TRIAL_SOURCE_PAID, $deal->trial_source);
    }

    /** @test */
    public function zoom_match_marks_attended_and_opens_follow_up(): void
    {
        config(['features.crm_trial_booking' => true]);
        $user = User::factory()->create(['email' => 'came@example.test']);
        $course = Course::factory()->create(['is_active' => true]);
        $schedule = Schedule::create([
            'title' => 'Прошлое вводное',
            'course_id' => $course->id,
            'start' => now()->subHours(3),
            'end' => now()->subHours(1),
        ]);
        $deal = Deal::factory()->trialFree()->create([
            'user_id' => $user->id,
            'lead_id' => Lead::factory()->create(['email' => 'came@example.test'])->id,
            'course_id' => $course->id,
            'schedule_id' => $schedule->id,
        ]);

        WebinarAttendance::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'zoom_participant_uuid' => 'p-came',
            'email' => 'came@example.test',
            'joined_at' => now()->subHours(3)->addMinutes(5),
            'duration_seconds' => 5400,
        ]);

        $this->artisan('crm:reconcile-trial-attendance')->assertSuccessful();

        $deal->refresh();
        $this->assertSame(Deal::TRIAL_OUTCOME_ATTENDED, $deal->trial_outcome);
        $this->assertSame(1, FollowUpTask::query()->open()->where('deal_id', $deal->id)->count());
        $this->assertSame('дожим после пробника', FollowUpTask::query()->where('deal_id', $deal->id)->value('note'));
    }

    /** @test */
    public function zoom_miss_leaves_booked_and_opens_confirm_task(): void
    {
        config(['features.crm_trial_booking' => true]);
        $schedule = Schedule::create([
            'title' => 'Прошлое вводное',
            'course_id' => Course::factory()->create()->id,
            'start' => now()->subHours(3),
            'end' => now()->subHours(1),
        ]);
        $deal = Deal::factory()->trialFree()->create([
            'lead_id' => Lead::factory()->create(['email' => 'ghost@example.test'])->id,
            'schedule_id' => $schedule->id,
            'course_id' => $schedule->course_id,
        ]);

        $this->artisan('crm:reconcile-trial-attendance')->assertSuccessful();

        $deal->refresh();
        $this->assertSame(Deal::TRIAL_OUTCOME_BOOKED, $deal->trial_outcome);
        $this->assertSame('подтвердить посещение', FollowUpTask::query()->where('deal_id', $deal->id)->value('note'));
    }

    /** @test */
    public function staff_override_to_no_show_sticks(): void
    {
        config(['features.crm_trial_booking' => true]);
        $actor = User::factory()->create(['role' => 'manager']);
        $deal = Deal::factory()->trialFree()->create();

        $this->bookings()->applyOutcome($deal, Deal::TRIAL_OUTCOME_NO_SHOW, $actor);

        $this->assertSame(Deal::TRIAL_OUTCOME_NO_SHOW, $deal->fresh()->trial_outcome);
    }

    /** @test */
    public function kanban_title_prefixes_probnik_when_flag_on(): void
    {
        config(['features.crm_trial_booking' => true]);
        $deal = Deal::factory()->trialFree()->create();

        $this->assertStringStartsWith('Пробник · ', $deal->kanban_title);

        config(['features.crm_trial_booking' => false]);
        $this->assertFalse(str_starts_with($deal->fresh()->kanban_title, 'Пробник · '));
    }

    /** @test */
    public function observer_tag_is_idempotent_on_repeated_save(): void
    {
        config([
            'features.crm_pipeline_board' => true,
            'features.crm_trial_booking' => true,
        ]);

        $payment = Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        app(PaymentDealBridgeObserver::class)->created($payment);
        $payment->update(['status' => 'success']);

        $this->assertSame(1, Deal::query()->count());
    }
}
