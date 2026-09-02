<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * schedule-links watchdog (инцидент 02-09-2026, курсы 401/399): серии занятий
 * без ссылок — soft-находка пробы + прямой звонок кураторам (дедуп 24h).
 */
class CabinetProbeScheduleLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '');
        config()->set('cabinet_probe.telegram_soft_chat_id', '');
        config()->set('cabinet_probe.soft_webhook_url', '');
        config()->set('cabinet_probe.check_server_guards', false);
        config()->set('cabinet_probe.check_payment_tls', false);
        config()->set('cabinet_probe.check_homework_upload', false);
        config()->set('cabinet_probe.check_deploy_drift', false);
        config()->set('cabinet_probe.public_surfaces', []);
        config()->set('cabinet_probe.surfaces', []);
        config()->set('cabinet_probe.student_surfaces', []);
        config()->set('cabinet_probe.hybrid_surfaces', []);
        config()->set('services.telegram.bot_token', '');
        config()->set('services.test_manager.email', '');
        config()->set('services.test_student.email', '');
        config()->set('app.env', 'production');
        config()->set('services.telegram.curators_chat_id', '-100curators');
        Cache::flush();
    }

    private function makeCourse(string $title, ?string $zoomLink = null): Course
    {
        return Course::create(['title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'zoom_link' => $zoomLink]);
    }

    public function test_missing_link_reports_soft_and_notifies_curators(): void
    {
        Queue::fake();

        $course = $this->makeCourse('Курс без ссылок');
        $group = Group::create(['name' => 'Группа TG', 'telegram_chat_id' => '-100777']);
        Schedule::create(['title' => 'Занятие 1', 'start' => now()->addDays(3), 'group_id' => $group->id, 'course_id' => $course->id]);
        Schedule::create(['title' => 'Занятие 2', 'start' => now()->addDays(4), 'group_id' => $group->id, 'course_id' => $course->id]);

        $this->artisan('cabinet:probe')->assertSuccessful();

        // Один звонок кураторам на курс (дедуп внутри нотификатора).
        Queue::assertPushed(\App\Jobs\SendTelegramChatMessageJob::class, 1);
        Queue::assertPushed(\App\Jobs\SendTelegramChatMessageJob::class, fn ($job) => $job->chatId === '-100curators'
            && str_contains($job->text, 'Курс без ссылок')
            && str_contains($job->text, 'Ссылка Zoom'));
    }

    public function test_course_with_link_is_silent(): void
    {
        Queue::fake();

        $course = $this->makeCourse('Курс со ссылкой', 'https://zoom.us/j/ok');
        $group = Group::create(['name' => 'Группа TG', 'telegram_chat_id' => '-100777']);
        Schedule::create(['title' => 'Занятие', 'start' => now()->addDays(3), 'group_id' => $group->id, 'course_id' => $course->id]);

        $this->artisan('cabinet:probe')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_schedule_link_on_the_schedule_itself_is_silent(): void
    {
        Queue::fake();

        $course = $this->makeCourse('Курс без zoom_link');
        $group = Group::create(['name' => 'Группа TG', 'telegram_chat_id' => '-100777']);
        Schedule::create([
            'title' => 'Занятие', 'start' => now()->addDays(3), 'group_id' => $group->id,
            'course_id' => $course->id, 'link' => 'https://zoom.us/j/per-schedule',
        ]);

        $this->artisan('cabinet:probe')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_group_without_telegram_chat_is_ignored(): void
    {
        Queue::fake();

        $course = $this->makeCourse('Курс без TG-чата');
        $group = Group::create(['name' => 'Группа без чата']);
        Schedule::create(['title' => 'Занятие', 'start' => now()->addDays(3), 'group_id' => $group->id, 'course_id' => $course->id]);

        $this->artisan('cabinet:probe')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_notify_curators(): void
    {
        Queue::fake();

        $course = $this->makeCourse('Курс без ссылок dry');
        $group = Group::create(['name' => 'Группа TG', 'telegram_chat_id' => '-100777']);
        Schedule::create(['title' => 'Занятие', 'start' => now()->addDays(3), 'group_id' => $group->id, 'course_id' => $course->id]);

        $this->artisan('cabinet:probe', ['--dry' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_curator_dedup_24h_per_course(): void
    {
        Queue::fake();

        $course = $this->makeCourse('Курс дедупа');
        $group = Group::create(['name' => 'Группа TG', 'telegram_chat_id' => '-100777']);
        Schedule::create(['title' => 'Занятие', 'start' => now()->addDays(3), 'group_id' => $group->id, 'course_id' => $course->id]);

        $this->artisan('cabinet:probe')->assertSuccessful();
        $this->artisan('cabinet:probe')->assertSuccessful();

        Queue::assertPushed(\App\Jobs\SendTelegramChatMessageJob::class, 1);
    }
}
