<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Course;
use App\Models\Group;
use App\Models\LandingPage;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOutreach;
use App\Services\WaitlistNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Обратная связь листу ожидания одной группы курса (H3327):
 * живой статус-текст, доставка сматченным ученикам, аудит рассылок,
 * дедуп авто-напоминания за 2 дня и рендер статус-блока на лендинге.
 */
class WaitlistFeedbackLoopTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(array $attrs = []): Group
    {
        return Group::create(array_merge([
            'name' => 'КШ-3 сб',
            'slug' => 'ksh3-'.uniqid(),
            'status' => 'forming',
            'min_size' => 8,
            'planned_start_date' => today()->addDays(2),
        ], $attrs));
    }

    private function addWaitlist(Group $group, ?int $userId = null): WaitlistEntry
    {
        return WaitlistEntry::create([
            'group_id' => $group->id,
            'user_id' => $userId,
            'name' => $userId ? 'Ученик кабинета' : 'Гость',
            'contact' => '+7999000000'.random_int(1, 9),
        ]);
    }

    /** @test */
    public function status_text_shows_how_many_participants_are_still_needed(): void
    {
        $group = $this->makeGroup();

        $text = WaitlistNotifier::statusText($group);

        $this->assertStringContainsString('Идёт набор', $text);
        $this->assertStringContainsString('старт ориентировочно', $text);
        $this->assertStringContainsString('до запуска нужно ещё 8 участников', $text);
    }

    /** @test */
    public function one_missing_participant_reads_in_singular(): void
    {
        $group = $this->makeGroup(['min_size' => 1]);

        $this->assertStringContainsString('до запуска нужен ещё 1 участник', WaitlistNotifier::statusText($group));
    }

    /** @test */
    public function recruited_group_reports_exact_start_time_from_schedule(): void
    {
        $group = $this->makeGroup();
        for ($i = 0; $i < 8; $i++) {
            $group->users()->attach(User::factory()->create()->id);
        }

        Schedule::create([
            'title' => 'Занятие 1',
            'start' => today()->addDay()->setTime(11, 30),
            'end' => today()->addDay()->setTime(13, 0),
            'group_id' => $group->id,
        ]);

        $text = WaitlistNotifier::statusText($group);

        $this->assertStringContainsString('Группа набрана', $text);
        $this->assertStringContainsString('в 11:30', $text);
    }

    /** @test */
    public function notify_sends_to_matched_students_and_audits_the_rest(): void
    {
        Queue::fake();

        $group = $this->makeGroup();
        $studentId = User::factory()->create()->id;
        $this->addWaitlist($group, $studentId);
        $this->addWaitlist($group);

        $result = app(WaitlistNotifier::class)->notify($group, WaitlistNotifier::KIND_MANUAL, 'Тест статуса');

        Queue::assertPushed(SendMessengerAlerts::class, 1);
        $this->assertSame(1, $result['messengers']);
        $this->assertSame(1, $result['manual']);

        $outreach = WaitlistOutreach::latest('id')->first();
        $this->assertSame(WaitlistNotifier::KIND_MANUAL, $outreach->kind);
        $this->assertSame('Тест статуса', $outreach->text);
        $this->assertSame(1, $outreach->messengers_count);
        $this->assertSame(1, $outreach->manual_count);
        $this->assertTrue($group->waitlistEntries->every(fn ($e) => $e->last_outreach_at !== null));
    }

    /** @test */
    public function transfer_text_carries_the_new_date(): void
    {
        $group = $this->makeGroup(['planned_start_date' => today()->addDays(7)]);

        $text = WaitlistNotifier::transferText($group, today()->addDays(14));

        $this->assertStringContainsString('перенесён на', $text);
        $this->assertStringContainsString(today()->addDays(14)->isoFormat('D MMMM YYYY'), $text);
    }

    /** @test */
    public function auto_reminder_is_deduplicated_within_one_day(): void
    {
        $group = $this->makeGroup();
        $this->addWaitlist($group);

        $first = app(WaitlistNotifier::class)->autoReminder($group);
        $second = app(WaitlistNotifier::class)->autoReminder($group);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, WaitlistOutreach::where('kind', WaitlistNotifier::KIND_AUTO_REMINDER)->count());
    }

    /** @test */
    public function shortfall_command_also_reminds_the_waitlist_two_days_before_start(): void
    {
        Queue::fake();

        $group = $this->makeGroup(); // forming, старт через 2 дня, min_size 8, состав пуст
        $this->addWaitlist($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        $this->assertDatabaseHas('waitlist_outreaches', [
            'group_id' => $group->id,
            'kind' => WaitlistNotifier::KIND_AUTO_REMINDER,
        ]);
    }

    /** @test */
    public function landing_renders_live_status_block_with_vocabulary(): void
    {
        Queue::fake();

        $course = Course::factory()->create(['course_family' => 'test-family']);
        $group = $this->makeGroup(['min_size' => 5]);
        $group->courses()->attach($course->id);

        $landing = LandingPage::create([
            'title' => 'Статус',
            'slug' => 'status-'.uniqid(),
            'is_active' => true,
            'content' => [['type' => 'status_block', 'data' => ['course_family' => 'test-family']]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Статус курса');
        $response->assertSee('до запуска нужно ещё 5 участников');
        // Словарь статусов виден сразу — подписчик знает, чего ожидать.
        $response->assertSee('Группа набрана');
        $response->assertSee('Отложено на другой сезон');
    }

    /** @test */
    public function stale_shell_groups_without_threshold_or_date_are_ignored(): void
    {
        $course = Course::factory()->create(['course_family' => 'shell-family']);
        $shell = Group::create([
            'name' => 'Оболочка без порога',
            'slug' => 'shell-'.uniqid(),
            'status' => 'forming', // нет min_size и planned_start_date — не запуск
        ]);
        $course->groups()->attach($shell->id);

        $landing = LandingPage::create([
            'title' => 'Пусто',
            'slug' => 'empty-'.uniqid(),
            'is_active' => true,
            'content' => [['type' => 'status_block', 'data' => ['course_family' => 'shell-family']]],
        ]);

        $this->get('/'.$landing->slug)
            ->assertOk()
            ->assertDontSee('Статус курса');
    }
}
