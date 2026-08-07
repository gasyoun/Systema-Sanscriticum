<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\Course;
use App\Models\FollowUpTask;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\SupportConversation;
use App\Models\SupportConversationTopic;
use App\Models\SupportTopicRule;
use App\Models\User;
use App\Services\Support\HelpdeskStudentContextService;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\SupportConversationTopicService;
use App\Services\Support\SupportFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H2381 — EdTech context + required close topic + support follow-up.
 */
class HelpdeskOperatorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_context_includes_groups_access_and_recent_conversations(): void
    {
        $student = User::factory()->create(['name' => 'Студент Контекст']);
        $course = Course::factory()->create(['title' => 'Курс Бета', 'is_active' => true]);
        $group = Group::factory()->create(['name' => 'Группа Утро']);
        $group->courses()->attach($course->id);
        $student->groups()->attach($group->id);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Урок 1',
            'block_number' => 1,
            'sort_order' => 1,
        ]);
        Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Урок 2',
            'block_number' => 2,
            'sort_order' => 2,
        ]);

        $manager = app(SupportConversationManager::class);
        $thread = $manager->openFor($student);
        $manager->close($thread);
        $manager->openFor($student); // reopen preserves history

        $ctx = app(HelpdeskStudentContextService::class)->forUser($student->fresh());

        $this->assertNotEmpty($ctx['groups']);
        $this->assertSame('Группа Утро', $ctx['groups'][0]['name']);
        $this->assertNotEmpty($ctx['access']);
        $this->assertContains('block_1', $ctx['access'][0]['tariffs']);
        $blockMap = collect($ctx['access'][0]['blocks'])->keyBy('block');
        $this->assertTrue($blockMap[1]['unlocked']);
        $this->assertFalse($blockMap[2]['unlocked']);
        $this->assertNotEmpty($ctx['recent_conversations']);
    }

    public function test_close_without_topic_allowed_when_flag_off(): void
    {
        config(['features.support_required_close_topic' => false]);

        $user = User::factory()->create();
        $manager = app(SupportConversationManager::class);
        $thread = $manager->openFor($user);

        $manager->closeWithTopic($thread);

        $this->assertSame(SupportConversation::STATUS_CLOSED, $thread->fresh()->status);
        $this->assertSame(0, SupportConversationTopic::query()->count());
    }

    public function test_close_requires_topic_when_flag_on(): void
    {
        config(['features.support_required_close_topic' => true]);

        SupportTopicRule::create([
            'category' => 'access',
            'keywords' => ['доступ'],
            'priority' => 1,
            'is_enabled' => true,
        ]);

        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $manager = app(SupportConversationManager::class);
        $thread = $manager->openFor($user);

        $this->expectException(\InvalidArgumentException::class);
        $manager->closeWithTopic($thread, null, $admin);
    }

    public function test_close_with_explicit_other_and_history_survives_reopen(): void
    {
        config(['features.support_required_close_topic' => true]);

        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $manager = app(SupportConversationManager::class);
        $thread = $manager->openFor($user);

        $manager->closeWithTopic($thread, SupportConversationTopic::CATEGORY_OTHER, $admin, 'ручная тема');
        $this->assertSame(SupportConversation::STATUS_CLOSED, $thread->fresh()->status);

        $current = app(SupportConversationTopicService::class)->currentTopic($thread);
        $this->assertSame(SupportConversationTopic::CATEGORY_OTHER, $current?->category);

        $reopened = $manager->openFor($user);
        $this->assertSame($thread->id, $reopened->id);
        $this->assertSame(1, SupportConversationTopic::query()->where('support_conversation_id', $thread->id)->count());

        $manager->closeWithTopic($reopened, SupportConversationTopic::CATEGORY_UNCATEGORIZED, $admin);
        $this->assertSame(2, SupportConversationTopic::query()->where('support_conversation_id', $thread->id)->count());
        $this->assertSame(
            SupportConversationTopic::CATEGORY_UNCATEGORIZED,
            app(SupportConversationTopicService::class)->currentTopic($thread->fresh())?->category
        );
    }

    public function test_support_follow_up_create_complete_and_flag_gate(): void
    {
        config(['features.support_follow_up_tasks' => false]);

        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $thread = app(SupportConversationManager::class)->openFor($user);

        try {
            app(SupportFollowUpService::class)->create($thread, now()->addDay(), $admin);
            $this->fail('Expected InvalidArgumentException when flag OFF');
        } catch (\InvalidArgumentException) {
            // expected
        }

        config(['features.support_follow_up_tasks' => true]);

        $task = app(SupportFollowUpService::class)->create(
            $thread,
            now()->subDay(),
            $admin,
            FollowUpTask::TYPE_MESSAGE,
            'проверить оплату',
        );

        $this->assertNull($task->deal_id);
        $this->assertSame($thread->id, $task->support_conversation_id);
        $this->assertSame('проверить оплату', $task->note);
        $this->assertTrue($task->isOverdue());
        $this->assertTrue($task->isSupportTask());

        app(SupportFollowUpService::class)->complete($task);
        $this->assertTrue($task->fresh()->isDone());
        $this->assertSame(0, app(SupportFollowUpService::class)->openForConversation($thread)->count());
    }

    public function test_helpdesk_resolve_and_follow_up_livewire(): void
    {
        config([
            'features.support_required_close_topic' => true,
            'features.support_follow_up_tasks' => true,
        ]);

        SupportTopicRule::create([
            'category' => 'payment',
            'keywords' => ['оплат'],
            'priority' => 1,
            'is_enabled' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $student = User::factory()->create(['name' => 'Студент Livewire', 'last_activity_at' => now()]);
        $manager = app(SupportConversationManager::class);
        $manager->openFor($student);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->set('closeTopicCategory', 'payment')
            ->call('resolveConversation')
            ->assertHasNoErrors();

        $this->assertSame(
            SupportConversation::STATUS_CLOSED,
            SupportConversation::where('user_id', $student->id)->first()?->status
        );

        // reopen for follow-up UI
        $manager->openFor($student);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->set('followUpDueAt', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('followUpNote', 'напомнить про ДЗ')
            ->set('followUpType', FollowUpTask::TYPE_MESSAGE)
            ->call('createSupportFollowUp')
            ->assertHasNoErrors();

        $this->assertSame(1, FollowUpTask::query()->forSupport()->open()->count());
    }

    public function test_helpdesk_student_info_shows_edtech_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $student = User::factory()->create(['name' => 'Студент EdTech', 'last_activity_at' => now()]);
        $course = Course::factory()->create(['title' => 'Курс Гамма', 'is_active' => true]);
        $group = Group::factory()->create(['name' => 'Вечер']);
        $group->courses()->attach($course->id);
        $student->groups()->attach($group->id);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('openStudentInfo')
            ->assertSee('Студент EdTech')
            ->assertSee('Вечер')
            ->assertSee('Активные группы');
    }
}
