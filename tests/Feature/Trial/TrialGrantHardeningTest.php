<?php

declare(strict_types=1);

namespace Tests\Feature\Trial;

use App\Jobs\SendTelegramMessageJob;
use App\Mail\TrialZoomLinkMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5001 — пробное занятие: оплата прошла, доступ не выдан (дефект A), и грант
 * на пустой урок-заготовку вместо урока с записью (дефект B). Всё поведение —
 * за флагом features.trial_grant_hardening (default OFF).
 */
class TrialGrantHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-10 12:00:00');
        Queue::fake();
        Mail::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function group(): int
    {
        return Group::factory()->create()->id;
    }

    /** Курс с пробным на событие расписания; флаг на момент сохранения OFF (как на проде). */
    private function courseWithTrial(Carbon $start, ?int $groupId = null): Course
    {
        $course = Course::factory()->create();
        $schedule = Schedule::create([
            'title' => 'Пробное',
            'course_id' => $course->id,
            'group_id' => $groupId,
            'start' => $start,
            'link' => 'https://zoom.us/j/1',
        ]);
        $course->update(['trial_price' => 750, 'trial_schedule_id' => $schedule->id]);

        return $course->fresh();
    }

    private function payTrial(Course $course): User
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 750, 'tariff' => 'trial', 'status' => 'pending',
        ]);
        $payment->update(['status' => 'paid']);

        return $user;
    }

    private function assertNoRecordingOpenMessages(User $user): void
    {
        Mail::assertNotQueued(TrialZoomLinkMail::class);
        Mail::assertNotSent(TrialZoomLinkMail::class);
        Queue::assertNotPushed(SendTelegramMessageJob::class, fn ($job) => $job->userId === $user->id
            && str_contains($job->text, 'Пробное занятие оплачено'));
    }

    private function assertAdminAlerted(): void
    {
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage')
            && $r['chat_id'] === '111'
            && str_contains($r['text'], 'доступ НЕ выдан'));
    }

    /** @test */
    public function empty_trial_lesson_id_grants_nothing_and_sends_no_recording_open_message(): void
    {
        config(['features.trial_grant_hardening' => true]);
        $course = $this->courseWithTrial(Carbon::parse('2026-07-07 07:00'));
        $course->updateQuietly(['trial_lesson_id' => null]);

        $user = $this->payTrial($course);

        $this->assertSame(0, LessonAccessGrant::where('user_id', $user->id)->count());
        $this->assertNoRecordingOpenMessages($user);
        $this->assertAdminAlerted();
    }

    /** @test */
    public function past_class_grants_the_lesson_with_the_recording_not_the_empty_placeholder(): void
    {
        // Прод-форма курса 436: заготовка (своя группа, без видео) + настоящий урок
        // той же даты с rutube-записью (group NULL).
        $course = $this->courseWithTrial(Carbon::parse('2026-07-07 07:00'), $this->group());
        $placeholder = Lesson::findOrFail($course->trial_lesson_id);
        $this->assertFalse($placeholder->hasVideo());

        $real = Lesson::create([
            'course_id' => $course->id, 'group_id' => null, 'lesson_date' => '2026-07-07',
            'title' => 'Занятие 07.07', 'block_number' => 1, 'is_published' => true,
            'rutube_url' => 'https://rutube.ru/video/abc/',
        ]);
        $this->assertNotNull($real->fresh()->recording_attached_at);

        config(['features.trial_grant_hardening' => true]);
        $user = $this->payTrial($course->fresh());

        $grants = LessonAccessGrant::where('user_id', $user->id)->active()->pluck('lesson_id')->all();
        $this->assertSame([$real->id], $grants);
        Mail::assertQueued(TrialZoomLinkMail::class, fn ($m) => $m->hasTo($user->email) && $m->isRecording === true);
    }

    /** @test */
    public function past_class_without_any_recorded_lesson_grants_nothing_and_alerts(): void
    {
        $course = $this->courseWithTrial(Carbon::parse('2026-07-07 07:00'), $this->group());
        config(['features.trial_grant_hardening' => true]);

        $user = $this->payTrial($course);

        $this->assertSame(0, LessonAccessGrant::where('user_id', $user->id)->count());
        $this->assertNoRecordingOpenMessages($user);
        $this->assertAdminAlerted();
    }

    /** @test */
    public function upcoming_class_still_grants_the_placeholder_for_the_live_session(): void
    {
        config(['features.trial_grant_hardening' => true]);
        $course = $this->courseWithTrial(Carbon::parse('2026-07-14 07:00'));

        $user = $this->payTrial($course);

        $this->assertSame(1, LessonAccessGrant::where('user_id', $user->id)
            ->where('lesson_id', $course->trial_lesson_id)->active()->count());
        Mail::assertQueued(TrialZoomLinkMail::class, fn ($m) => $m->isRecording === false && $m->zoomLink !== null);
    }

    /** @test */
    public function flag_off_keeps_legacy_behaviour(): void
    {
        $this->assertFalse((bool) config('features.trial_grant_hardening'));
        $course = $this->courseWithTrial(Carbon::parse('2026-07-07 07:00'));
        $course->updateQuietly(['trial_lesson_id' => null]);

        $user = $this->payTrial($course);

        // Прежнее (дефектное) поведение без флага: гранта нет, письмо уходит.
        $this->assertSame(0, LessonAccessGrant::where('user_id', $user->id)->count());
        Mail::assertQueued(TrialZoomLinkMail::class);
        Http::assertNothingSent();
    }

    /** @test */
    public function saving_course_pins_the_recorded_lesson_for_a_past_class_when_flag_on(): void
    {
        $course = $this->courseWithTrial(Carbon::parse('2026-07-07 07:00'), $this->group());
        $placeholderId = $course->trial_lesson_id;
        $real = Lesson::create([
            'course_id' => $course->id, 'group_id' => null, 'lesson_date' => '2026-07-07',
            'title' => 'Занятие 07.07', 'block_number' => 1, 'is_published' => true,
            'video_url' => 'https://cdn.example/rec.mp4',
        ]);

        config(['features.trial_grant_hardening' => true]);
        $course->update(['trial_price' => 800]);

        $this->assertSame($real->id, $course->fresh()->trial_lesson_id);
        $this->assertNotSame($placeholderId, $real->id);
    }

    /** @test */
    public function target_watch_flags_courses_whose_trial_has_nothing_to_open(): void
    {
        $broken = $this->courseWithTrial(Carbon::parse('2026-07-07 07:00'), $this->group());
        $healthy = $this->courseWithTrial(Carbon::parse('2026-07-14 07:00'));

        $this->artisan('trial:target-watch --notify')
            ->expectsOutputToContain('прошло, урока с записью нет')
            ->assertFailed();

        Http::assertSent(fn (Request $r) => str_contains($r['text'], '#'.$broken->id)
            && ! str_contains($r['text'], '#'.$healthy->id.' '));
    }
}
