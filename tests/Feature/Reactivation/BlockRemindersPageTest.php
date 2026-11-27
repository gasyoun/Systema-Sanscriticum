<?php

declare(strict_types=1);

namespace Tests\Feature\Reactivation;

use App\Filament\Pages\CourseBlockParticipants;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\DebtReminder;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Services\CourseBlockParticipantsReport;
use App\Services\Reactivation\BlockReminderSender;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Экран «Участники по блокам»: должники блока и мини-рассылка с него.
 */
class BlockRemindersPageTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $course = Course::factory()->create();
        foreach ([1, 2] as $n) {
            CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'title' => "Б{$n}"]);
        }

        $group = Group::create(['name' => 'Группа A']);
        $course->groups()->attach($group->id);

        $payer = User::factory()->create(['role' => Roles::ADMIN, 'email' => 'payer@example.com']);
        $debtor = User::factory()->create(['role' => null, 'email' => 'debtor@example.com']);
        // Тот, кто не платил ни разу: в матрице «студент × блок» его нет,
        // но в группах курса он есть — и он первый адресат напоминания.
        $neverPaid = User::factory()->create(['role' => null, 'email' => 'never@example.com']);

        $group->users()->attach([$payer->id, $debtor->id, $neverPaid->id]);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $payer->id,
            'course_id' => $course->id,
            'amount' => 100,
            'status' => 'paid',
            'tariff' => 'block_1',
        ]));

        return [$course, $payer, $debtor, $neverPaid];
    }

    /** @test */
    public function debtors_of_a_block_are_group_members_without_access_to_it(): void
    {
        [$course, $payer, $debtor, $neverPaid] = $this->fixtures();

        $ids = array_column(app(CourseBlockParticipantsReport::class)->debtorsFor($course, 1), 'id');

        $this->assertNotContains($payer->id, $ids, 'оплативший блок 1 не должник');
        $this->assertContains($debtor->id, $ids, 'не плативший блок 1 — должник');
        $this->assertContains($neverPaid->id, $ids, 'не плативший ни разу — должник');
    }

    /** @test */
    public function page_lists_debtors_and_sends_reminder_to_selected(): void
    {
        Mail::fake();
        Queue::fake();

        [$course, $payer, $debtor, $neverPaid] = $this->fixtures();

        $this->actingAs($payer);

        Livewire::test(CourseBlockParticipants::class)
            ->set('courseId', $course->id)
            ->set('reminderBlock', 1)
            ->assertSee('Напоминание об оплате')
            ->set('reminderIds', [$debtor->id, $neverPaid->id])
            ->set('reminderAccount', false)
            ->set('reminderBot', false)
            ->set('reminderEmail', true)
            ->call('sendReminders')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('debt_reminders', [
            'user_id' => $debtor->id,
            'course_id' => $course->id,
            'block_number' => 1,
            'source' => BlockReminderSender::SOURCE,
        ]);
        $this->assertDatabaseHas('debt_reminders', [
            'user_id' => $neverPaid->id,
            'course_id' => $course->id,
            'block_number' => 1,
            'source' => BlockReminderSender::SOURCE,
        ]);
        $this->assertDatabaseMissing('debt_reminders', ['user_id' => $payer->id]);
    }

    /** @test */
    public function page_refuses_to_send_without_channels(): void
    {
        Mail::fake();
        Queue::fake();

        [$course, $payer, $debtor] = $this->fixtures();

        $this->actingAs($payer);

        Livewire::test(CourseBlockParticipants::class)
            ->set('courseId', $course->id)
            ->set('reminderBlock', 1)
            ->set('reminderIds', [$debtor->id])
            ->set('reminderAccount', false)
            ->set('reminderBot', false)
            ->set('reminderEmail', false)
            ->call('sendReminders')
            ->assertHasNoErrors();

        $this->assertSame(0, DebtReminder::query()->count());
    }

    /** @test */
    public function page_refuses_to_send_without_selected_students(): void
    {
        Mail::fake();
        Queue::fake();

        [$course, $payer] = $this->fixtures();

        $this->actingAs($payer);

        Livewire::test(CourseBlockParticipants::class)
            ->set('courseId', $course->id)
            ->set('reminderBlock', 1)
            ->set('reminderIds', [])
            ->call('sendReminders')
            ->assertHasNoErrors();

        $this->assertSame(0, DebtReminder::query()->count());
    }
}
