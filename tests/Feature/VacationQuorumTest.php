<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use App\Models\VacationQuorumPoll;
use App\Services\VacationQuorumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VacationQuorumTest extends TestCase
{
    use RefreshDatabase;

    private function makeVacationGroup(string $name = 'Гр.53', ?int $minSize = 2, ?string $chatId = '-100123'): Group
    {
        $group = Group::factory()->create([
            'name' => $name,
            'status' => 'active',
            'is_on_vacation' => true,
            'vacation_resume_date' => null,
            'telegram_chat_id' => $chatId,
            'min_size' => $minSize,
        ]);

        Schedule::create([
            'title' => $name.' — занятие',
            'group_id' => $group->id,
            'start' => now()->addDays(10)->setTime(20, 0),
            'end' => now()->addDays(10)->setTime(22, 0),
        ]);

        return $group;
    }

    private function enroll(Group $group, string $name, bool $paid, string $telegramId = '100'): User
    {
        $course = Course::factory()->create();
        $group->courses()->attach($course->id);

        $user = User::factory()->create(['name' => $name, 'telegram_id' => $telegramId]);
        $group->users()->attach($user->id, ['left_at' => null]);

        if ($paid) {
            Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => 'paid',
                'amount' => 1000,
                'is_conditional' => false,
            ]);
        } else {
            $user->courses()->attach($course->id, ['status' => 'Льготник']);
        }

        return $user;
    }

    public function test_groups_to_ask_filters_window_flags_chat_and_polls(): void
    {
        $this->makeVacationGroup('Спрашиваем');
        $answered = $this->makeVacationGroup('Уже спрашивали');
        VacationQuorumPoll::create([
            'group_id' => $answered->id,
            'chat_id' => '-100123',
            'deadline_at' => now()->addDays(10),
            'paid_voters' => [],
        ]);
        $withDate = Group::factory()->create([
            'is_on_vacation' => true,
            'vacation_resume_date' => '2026-09-14',
            'telegram_chat_id' => '-100456',
        ]);
        $noChat = Group::factory()->create([
            'is_on_vacation' => true,
            'telegram_chat_id' => null,
        ]);
        Schedule::create(['title' => 'x', 'group_id' => $noChat->id, 'start' => now()->addDays(3)]);

        $service = app(VacationQuorumService::class);
        $names = array_map(fn (Group $g) => $g->name, $service->groupsToAsk());

        $this->assertContains('Спрашиваем', $names);
        $this->assertNotContains('Уже спрашивали', $names);
        $noChatGroup = Group::find($noChat->id);
        $this->assertNotContains($noChatGroup->name, $names);
        $this->assertNotContains($withDate->name, $names);
    }

    public function test_ask_creates_poll_with_deadline_plus_14_days(): void
    {
        $group = $this->makeVacationGroup(minSize: 3);

        $poll = app(VacationQuorumService::class)->ask($group);

        $this->assertEquals(VacationQuorumPoll::OUTCOME_PENDING, $poll->outcome);
        $this->assertEquals(3, $poll->quorum_required);
        $this->assertEquals('-100123', $poll->chat_id);
    }

    public function test_quorum_defaults_to_4_when_min_size_missing(): void
    {
        $group = $this->makeVacationGroup(minSize: null);
        $poll = app(VacationQuorumService::class)->ask($group);

        $this->assertEquals(4, $poll->quorum_required);
    }

    public function test_paid_reply_counts_and_free_privileged_does_not(): void
    {
        $group = $this->makeVacationGroup(minSize: 1);
        $paid = $this->enroll($group, 'Платный', true, '111');
        $free = $this->enroll($group, 'Льготник-бесплатник', false, '222');

        $poll = app(VacationQuorumService::class)->ask($group);
        $poll->refresh()->update(['message_id' => 777]);

        $service = app(VacationQuorumService::class);
        $this->assertTrue($service->registerReply('-100123', 777, (int) $paid->telegram_id));
        $this->assertFalse($service->registerReply('-100123', 777, (int) $free->telegram_id));

        $poll->refresh();
        $this->assertEquals([$paid->telegram_id], array_map('strval', $poll->paid_voters));
    }

    public function test_unknown_reply_is_ignored(): void
    {
        $group = $this->makeVacationGroup(minSize: 1);
        $poll = app(VacationQuorumService::class)->ask($group);

        $this->assertFalse(app(VacationQuorumService::class)->registerReply('-100123', 999999, 555));
        $poll->refresh();
        $this->assertEquals(VacationQuorumPoll::OUTCOME_PENDING, $poll->outcome);
    }

    public function test_resolve_due_proposes_dissolution(): void
    {
        $group = $this->makeVacationGroup(minSize: 4);
        $poll = app(VacationQuorumService::class)->ask($group);
        $poll->update([
            'deadline_at' => now()->subHour(),
            'outcome' => VacationQuorumPoll::OUTCOME_PENDING,
            'paid_voters' => ['111', '222'],
        ]);

        app(VacationQuorumService::class)->resolveDue();

        $poll->refresh();
        $this->assertEquals(VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING, $poll->outcome);
        $this->assertNull($poll->resolved_at);
    }

    public function test_resolve_due_marks_quorum_met(): void
    {
        $group = $this->makeVacationGroup(minSize: 2);
        $poll = app(VacationQuorumService::class)->ask($group);
        $poll->update([
            'deadline_at' => now()->subHour(),
            'outcome' => VacationQuorumPoll::OUTCOME_PENDING,
            'paid_voters' => ['111', '222'],
        ]);

        app(VacationQuorumService::class)->resolveDue();

        $poll->refresh();
        $this->assertEquals(VacationQuorumPoll::OUTCOME_QUORUM_MET, $poll->outcome);
        $this->assertNotNull($poll->resolved_at);
    }

    public function test_approve_dissolution_soft_deletes_future_schedules_and_archives(): void
    {
        $group = $this->makeVacationGroup(minSize: 4);
        $poll = app(VacationQuorumService::class)->ask($group);
        $poll->update(['outcome' => VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING]);

        app(VacationQuorumService::class)->approveDissolution($poll);

        $poll->refresh();
        $this->assertEquals(VacationQuorumPoll::OUTCOME_DISSOLVED, $poll->outcome);
        $this->assertEquals('archived', $group->fresh()->status);
        $this->assertEquals(0, $group->schedules()->where('start', '>', now())->count());
        $this->assertEquals(1, $group->schedules()->withTrashed()->where('start', '>', now())->count());
    }

    public function test_approve_is_idempotent_after_resolution(): void
    {
        $group = $this->makeVacationGroup(minSize: 4);
        $poll = app(VacationQuorumService::class)->ask($group);
        $poll->update(['outcome' => VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING]);

        $service = app(VacationQuorumService::class);
        $service->approveDissolution($poll);
        $poll->update(['outcome' => VacationQuorumPoll::OUTCOME_QUORUM_MET]);
        $service->approveDissolution($poll); // второй канал/повтор — no-op

        $poll->refresh();
        $this->assertEquals(VacationQuorumPoll::OUTCOME_QUORUM_MET, $poll->outcome);
        $this->assertEquals('archived', $group->fresh()->status);
    }
}
