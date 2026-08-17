<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\SupportConversation;
use App\Models\SupportConversationTopic;
use App\Models\User;
use App\Services\Crm\Customer360Snapshot;
use App\Services\Crm\CustomerTimelineService;
use App\Support\Roles;
use App\Support\Telephony\CallEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H2483 CRM Wave 1 — compose-only customer 360.
 *
 * Three own-data-shaped journeys plus the fail conditions: no payment write,
 * stage change only via Deal::moveToStage, query budget on a loaded card.
 */
class CustomerTimelineServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CustomerTimelineService
    {
        return app(CustomerTimelineService::class);
    }

    private function sale(User $user, Course $course, string $status = 'paid'): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => $status,
            'is_conditional' => false,
        ]));
    }

    /** @test */
    public function lead_to_paid_journey_nudges_first_cabinet_action(): void
    {
        $email = 'lead-paid@example.test';
        $lead = Lead::factory()->create([
            'name' => 'Анна Лид',
            'email' => $email,
            'contact' => $email,
            'status' => 'converted',
            'converted_at' => now(),
            'utm_source' => 'telegram',
            'utm_campaign' => 'spring',
        ]);
        $user = User::factory()->create(['name' => 'Анна Лид', 'email' => $email]);
        $course = Course::factory()->create(['title' => 'Санскрит 1', 'is_active' => true]);
        $payment = $this->sale($user, $course);
        Deal::factory()->won()->create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'source_payment_id' => $payment->id,
        ]);

        $snap = $this->service()->forUser($user);

        $this->assertSame(Customer360Snapshot::ACTION_LEARNING_NUDGE, $snap->nextActionCode());
        $this->assertSame('Deal', $snap->pipeline['owner']);
        $this->assertSame('paid', $snap->accessPayment['state']);
        $this->assertSame('Lead', $snap->attribution['owner']);
        $this->assertSame('telegram', $snap->attribution['utm_source']);
        $this->assertTrue(collect($snap->timeline)->contains(fn (array $row): bool => $row['kind'] === 'payment'));
        $this->assertStringContainsString('/admin/payments/'.$payment->id, (string) $snap->accessPayment['url']);
    }

    /** @test */
    public function support_to_recovery_journey_points_at_overdue_promise(): void
    {
        $user = User::factory()->create(['email' => 'recovery@example.test']);
        $course = Course::factory()->create(['is_active' => true]);
        PaymentPromise::withoutEvents(fn () => PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->subDays(5),
            'amount' => 3000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]));
        $thread = SupportConversation::create([
            'user_id' => $user->id,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now()->subHour(),
        ]);
        SupportConversationTopic::create([
            'support_conversation_id' => $thread->id,
            'category' => 'access',
            'source' => SupportConversationTopic::SOURCE_MANUAL,
        ]);

        $snap = $this->service()->forUser($user);

        $this->assertSame(Customer360Snapshot::ACTION_RECOVERY_PROMISE, $snap->nextActionCode());
        $this->assertSame('promise_overdue', $snap->accessPayment['state']);
        $this->assertSame('open', $snap->support['outcome']);
        $this->assertSame('access', $snap->support['topic']);
        $this->assertSame('PaymentPromise', $snap->nextAction['source']);
    }

    /** @test */
    public function learner_repeat_purchase_journey_opens_next_deal_action(): void
    {
        $user = User::factory()->create(['email' => 'repeat@example.test']);
        $course = Course::factory()->create(['is_active' => true]);
        $this->sale($user, $course);
        Deal::factory()->won()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'event_type' => ActivityEvent::FIRST_CABINET_ACTION,
            'created_at' => now()->subDay(),
        ]);

        $snap = $this->service()->forUser($user);

        $this->assertSame(Customer360Snapshot::ACTION_REPEAT_PURCHASE, $snap->nextActionCode());
        $this->assertSame('ActivityEvent', $snap->learning['owner']);
        $this->assertSame(ActivityEvent::FIRST_CABINET_ACTION, $snap->learning['event_type']);
    }

    /** @test */
    public function compose_stays_inside_the_query_budget_on_a_loaded_card(): void
    {
        $email = 'budget@example.test';
        $lead = Lead::factory()->create(['email' => $email, 'contact' => $email, 'utm_source' => 'vk']);
        $user = User::factory()->create(['email' => $email]);
        $course = Course::factory()->create(['is_active' => true]);
        $this->sale($user, $course);
        $deal = Deal::factory()->create(['user_id' => $user->id, 'lead_id' => $lead->id, 'course_id' => $course->id]);
        FollowUpTask::factory()->create(['deal_id' => $deal->id, 'due_at' => now()->addDay()]);
        SupportConversation::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'status' => SupportConversation::STATUS_CLOSED,
            'last_message_at' => now()->subDay(),
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'event_type' => ActivityEvent::CABINET_HOME_VIEW,
            'created_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service()->forUser($user);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            CustomerTimelineService::QUERY_BUDGET,
            $count,
            'customer 360 must not N+1 a loaded card (saw '.$count.' queries)'
        );
    }

    /** @test */
    public function next_action_create_and_complete_go_through_follow_up_task(): void
    {
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $deal = Deal::factory()->create();

        $task = $this->service()->createFollowUp(
            $deal,
            null,
            $manager,
            FollowUpTask::TYPE_CALL,
            now()->addDay(),
            'перезвонить',
        );

        $this->assertFalse($task->isDone());
        $this->assertTrue($task->deal->is($deal));
        $this->assertSame('перезвонить', $task->note);

        $this->service()->completeFollowUp($task);
        $this->assertTrue($task->fresh()->isDone());
    }

    /** @test */
    public function stage_write_uses_deal_move_to_stage_and_never_touches_payments(): void
    {
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        $payment = $this->sale($user, $course);
        $first = DealStage::firstStage() ?? $this->fail('no deal stages');
        $won = DealStage::won() ?? $this->fail('no won stage');
        $deal = Deal::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'stage_id' => $first->id,
            'source_payment_id' => $payment->id,
        ]);

        $this->service()->moveDealStage($deal, $won, $manager);

        $this->assertTrue($deal->fresh()->stage->is($won));
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(4800, (int) $payment->fresh()->amount);
        $this->assertSame(1, Payment::query()->count());
    }

    /**
     * H2748 — packet §4.2. CallEvent types are allow-listed ActivityEvent
     * rows (option 1, no call_events table); the timeline gives them their
     * own CallEvent-branded row instead of the generic ActivityEvent one.
     *
     * @test
     */
    public function call_requested_activity_composes_as_its_own_call_event_row(): void
    {
        $user = User::factory()->create();
        ActivityEvent::create([
            'user_id' => $user->id,
            'event_type' => CallEvent::REQUESTED,
            'event_data' => ['support_conversation_id' => null, 'follow_up_task_id' => null],
            'created_at' => now(),
        ]);

        $snap = $this->service()->forUser($user);

        $callRow = collect($snap->timeline)->first(fn (array $row): bool => $row['kind'] === 'call');
        $this->assertNotNull($callRow, 'call.requested must appear on the timeline as its own row');
        $this->assertSame('CallEvent', $callRow['owner']);
        $this->assertSame('Запрошен обратный звонок', $callRow['title']);

        // Not double-counted as a generic learning row, and the learning
        // summary owner stays empty — a call is not cabinet activity.
        $this->assertFalse(collect($snap->timeline)->contains(fn (array $row): bool => $row['kind'] === 'learning'));
        $this->assertNull($snap->learning);
    }

    /** @test */
    public function call_events_never_satisfy_the_learning_nudge_next_action(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        $this->sale($user, $course);
        Deal::factory()->won()->create(['user_id' => $user->id, 'course_id' => $course->id]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'event_type' => CallEvent::REQUESTED,
            'created_at' => now(),
        ]);

        $snap = $this->service()->forUser($user);

        // A call.requested event must not be read as first-cabinet-action —
        // the learning nudge still fires because no real learning happened.
        $this->assertSame(Customer360Snapshot::ACTION_LEARNING_NUDGE, $snap->nextActionCode());
    }

    /** @test */
    public function lead_only_subject_resolves_matching_user_by_email(): void
    {
        $email = 'match@example.test';
        $lead = Lead::factory()->create(['email' => $email, 'contact' => $email, 'status' => 'new']);
        User::factory()->create(['email' => $email, 'name' => 'Совпадение']);

        $snap = $this->service()->forLead($lead);

        $this->assertNotNull($snap->user);
        $this->assertSame('Совпадение', $snap->user->name);
        $this->assertSame(Customer360Snapshot::ACTION_LEAD_CONTACT, $snap->nextActionCode());
    }
}
