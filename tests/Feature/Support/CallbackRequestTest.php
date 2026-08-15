<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\FollowUpTask;
use App\Models\User;
use App\Support\Telephony\CallEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2747 — Phase 0/1 consented callback request. Flag default OFF, no
 * provider HTTP. See docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md.
 */
class CallbackRequestTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        config()->set('features.telephony_callback_request', true);
    }

    /** @test */
    public function show_page_404_when_flag_off(): void
    {
        config()->set('features.telephony_callback_request', false);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('student.support.callback'))
            ->assertNotFound();
    }

    /** @test */
    public function store_404_when_flag_off(): void
    {
        config()->set('features.telephony_callback_request', false);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('student.support.callback.store'), [
                'phone' => '+79001234567',
                'consent' => '1',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('follow_up_tasks', ['type' => FollowUpTask::TYPE_CALL]);
    }

    /** @test */
    public function guest_cannot_reach_the_form(): void
    {
        $this->enable();

        $this->get(route('student.support.callback'))->assertRedirect(route('login'));
        $this->post(route('student.support.callback.store'), [
            'phone' => '+79001234567',
            'consent' => '1',
        ])->assertRedirect(route('login'));
    }

    /** @test */
    public function consent_checkbox_is_required(): void
    {
        $this->enable();
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('student.support.callback.store'), [
                'phone' => '+79001234567',
            ])
            ->assertSessionHasErrors('consent');

        $this->assertDatabaseMissing('follow_up_tasks', ['type' => FollowUpTask::TYPE_CALL]);
    }

    /** @test */
    public function consented_submit_writes_follow_up_task_and_call_event(): void
    {
        $this->enable();
        $student = User::factory()->create(['phone' => null]);

        $this->actingAs($student)
            ->post(route('student.support.callback.store'), [
                'phone' => '+79001234567',
                'note' => 'Удобно после 18:00',
                'consent' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('follow_up_tasks', [
            'type' => FollowUpTask::TYPE_CALL,
            'note' => 'Удобно после 18:00',
        ]);

        $task = FollowUpTask::where('type', FollowUpTask::TYPE_CALL)->firstOrFail();
        $this->assertNotNull($task->support_conversation_id);
        $this->assertNull($task->done_at);

        $this->assertDatabaseHas('activity_events', [
            'user_id' => $student->id,
            'event_type' => CallEvent::REQUESTED,
        ]);

        $this->assertSame('+79001234567', $student->fresh()->phone);
    }

    /** @test */
    public function no_pstn_or_provider_side_effects(): void
    {
        $this->enable();
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('student.support.callback.store'), [
                'phone' => '+79001234567',
                'consent' => '1',
            ])
            ->assertRedirect();

        // The flag family for the gated PSTN/recording/department phases stays OFF —
        // this pass never touches them.
        $this->assertFalse((bool) config('features.telephony_pstn'));
        $this->assertFalse((bool) config('features.telephony_recording'));
    }
}
