<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Services\Schedule\DTO\GeneratorConfig;
use App\Services\Schedule\ScheduleGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GC-B1 rescope (H1642, MG-руление 19-07-2026, weekly `@DECIDE` → опция (b)):
 * авто-создание ОДНОЙ recurring Zoom-встречи НА КУРС, никогда на `Schedule`.
 * Триггер — первая генерация потока (`ScheduleGenerator::generate()`), см.
 * ScheduleGenerator::autoCreateCourseMeeting(). Единый-ручной-поток модель
 * (`eda8059`, 27-06-2026) стоит: флаг ВЫКЛ ⇒ поведение байт-в-байт как раньше.
 */
class ZoomAutoCreateTest extends TestCase
{
    use RefreshDatabase;

    private function configureZoomCreds(): void
    {
        config([
            'services.zoom.account_id' => 'acc-123',
            'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'secret',
        ]);
    }

    private function fakeZoom(string $meetingId = '999888777'): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => $meetingId,
                'join_url' => "https://us02web.zoom.us/j/{$meetingId}?pwd=xyz",
                'start_url' => "https://us02web.zoom.us/s/{$meetingId}?zak=abc",
            ], 201),
        ]);
    }

    private function config(int $groupId, ?int $courseId, ?string $link = null): GeneratorConfig
    {
        return new GeneratorConfig(
            groupId: $groupId,
            courseId: $courseId,
            title: 'Тестовый поток',
            startDate: Carbon::parse('2026-08-03'), // Monday
            startTime: '10:00',
            durationMinutes: 90,
            totalLessons: 2,
            startNumber: 1,
            startLessonIndex: 1,
            weekdays: [Carbon::MONDAY],
            template: '{TITLE} #{N}',
            skipDates: [],
            addDates: [],
            link: $link,
            preserve: false,
        );
    }

    /** @test */
    public function flag_off_no_auto_create_no_behaviour_change(): void
    {
        config(['features.zoom_auto_create' => false]);
        $this->configureZoomCreds();
        $group = Group::factory()->create();
        $course = Course::factory()->create(['zoom_link' => null, 'zoom_meeting_id' => null]);

        $created = app(ScheduleGenerator::class)->generate($this->config($group->id, $course->id));

        Http::assertNothingSent();
        $this->assertNull($course->fresh()->zoom_meeting_id);
        $this->assertNull($course->fresh()->zoom_link);
        $this->assertNotEmpty($created);
        // Ссылка занятия пуста — как и до H1642, когда у курса нет zoom_link.
        $this->assertNull($created->first()->link);
    }

    /** @test */
    public function flag_on_first_trigger_creates_exactly_one_meeting_and_persists_link(): void
    {
        config(['features.zoom_auto_create' => true]);
        $this->configureZoomCreds();
        $this->fakeZoom('111222333');
        $group = Group::factory()->create();
        $course = Course::factory()->create(['zoom_link' => null, 'zoom_meeting_id' => null]);

        $created = app(ScheduleGenerator::class)->generate($this->config($group->id, $course->id));

        Http::assertSentCount(2); // oauth token + create meeting
        $course->refresh();
        $this->assertSame('111222333', $course->zoom_meeting_id);
        $this->assertStringContainsString('111222333', (string) $course->zoom_link);
        // Единая ссылка курса копируется в каждое созданное занятие.
        $this->assertNotEmpty($created);
        foreach ($created as $schedule) {
            $this->assertSame($course->zoom_link, $schedule->link);
        }
    }

    /** @test */
    public function idempotent_second_trigger_does_not_call_the_api_again(): void
    {
        config(['features.zoom_auto_create' => true]);
        $this->configureZoomCreds();
        $this->fakeZoom('444555666');
        $group = Group::factory()->create();
        $course = Course::factory()->create(['zoom_link' => null, 'zoom_meeting_id' => null]);

        app(ScheduleGenerator::class)->generate($this->config($group->id, $course->id));
        $firstLink = $course->fresh()->zoom_link;
        Http::assertSentCount(2);

        // Вторая генерация потока для того же курса — meeting_id уже есть.
        app(ScheduleGenerator::class)->generate($this->config($group->id, $course->id));

        Http::assertSentCount(2); // ни одного нового запроса
        $this->assertSame($firstLink, $course->fresh()->zoom_link);
    }

    /** @test */
    public function manual_filament_path_still_works_unchanged(): void
    {
        config(['features.zoom_auto_create' => true]);
        $this->configureZoomCreds();
        Http::fake(); // ничего не должно быть отправлено

        $course = Course::factory()->create();
        $course->zoom_link = 'https://us02web.zoom.us/j/123456789?pwd=abc';
        $course->save();

        $this->assertSame('123456789', $course->fresh()->zoom_meeting_id);
        Http::assertNothingSent();
    }

    /**
     * Рескоуп-преемник `WebinarProviderSeamTest::test_zoom_create_meeting_stays_removed_per_gc_b1`
     * (H1642, деливерабл 5e): единый-ручной-поток модель стоит на уровне
     * per-`Schedule` — прямое создание Schedule (в обход ScheduleGenerator,
     * как это делает, например, ручное добавление занятия в Filament) НЕ
     * дёргает Zoom API и не трогает курс, даже когда флаг ВКЛ.
     */
    /** @test */
    public function per_schedule_creation_direct_schedule_never_calls_zoom(): void
    {
        config(['features.zoom_auto_create' => true]);
        $this->configureZoomCreds();
        Http::fake();

        $group = Group::factory()->create();
        $course = Course::factory()->create(['zoom_link' => null, 'zoom_meeting_id' => null]);

        Schedule::create([
            'title' => 'Одиночное занятие',
            'link' => null,
            'start' => Carbon::parse('2026-08-10 10:00'),
            'end' => Carbon::parse('2026-08-10 11:30'),
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);

        Http::assertNothingSent();
        $this->assertNull($course->fresh()->zoom_meeting_id);
    }
}
