<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Jobs\TrackLessonViewJob;
use App\Listeners\Backup\SplitUploadToYandex;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use App\Services\Support\VisitorGeoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\Events\BackupWasSuccessful;
use Tests\TestCase;

/**
 * H5298 — per-defect regression tests for the three repaired DYNAMIC
 * (non-command) observability seams left by the H5061 residual:
 *
 *  1. VisitorGeoResolver — unarmed/unknown geo driver was a SILENT null and
 *     both geo jobs (H1196 thread, H1197 presence) permanently baked
 *     visitor_geo_resolved_at: not_supported was indistinguishable from a
 *     genuine no-match. Must now warn loudly with state=not_supported.
 *  2. TrackLessonViewJob — vanished user/lesson was a SILENT return: the
 *     request-triggered lesson_open metric vanished with no trace. Must now
 *     warn loudly with state=unavailable (and still not retry — the source
 *     row is gone, requeueing cannot resurrect it).
 *  3. SplitUploadToYandex — an unarmed split_upload config silently skipped
 *     the off-site leg on BackupWasSuccessful (and on the resume command).
 *     Must now warn loudly with state=not_supported, behavior unchanged.
 *
 * No second monitoring store: everything rides the standard log channel;
 * alert destinations, TSV sinks and exit codes unchanged.
 */
final class ProbeDynamicMissingnessRepairsTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_resolver_with_unarmed_driver_is_loud_not_supported(): void
    {
        config()->set('support_geo.driver', 'null');
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $ctx): bool => str_contains($message, 'не вооружён')
                && $ctx['state'] === 'not_supported'
                && $ctx['driver'] === 'null'
        );

        $resolver = new VisitorGeoResolver;

        self::assertNull($resolver->resolve('203.0.113.7', ['city' => null]), 'behavior unchanged: unarmed driver still resolves to null');
    }

    public function test_geo_resolver_with_unknown_driver_is_loud_not_supported(): void
    {
        config()->set('support_geo.driver', 'typo-driver');
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $ctx): bool => str_contains($message, 'неизвестный гео-драйвер')
                && $ctx['state'] === 'not_supported'
                && $ctx['driver'] === 'typo-driver'
        );

        $resolver = new VisitorGeoResolver;

        self::assertNull($resolver->resolve('203.0.113.7'), 'behavior unchanged: unknown driver still resolves to null');
    }

    public function test_geo_resolver_with_armed_driver_stays_silent(): void
    {
        config()->set('support_geo.driver', 'cloudflare');
        Log::shouldReceive('warning')->never();

        $resolver = new VisitorGeoResolver;

        $geo = $resolver->resolve('203.0.113.7', ['city' => 'Москва', 'region' => null, 'country' => 'RU']);

        self::assertSame(['city' => 'Москва', 'region' => null, 'country' => 'RU'], $geo);
    }

    public function test_track_lesson_view_job_with_vanished_source_is_loud_unavailable(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $ctx): bool => str_contains($message, 'источник просмотра исчез')
                && $ctx['state'] === 'unavailable'
                && $ctx['user_id'] === 424242
                && $ctx['lesson_id'] === 424243
        );

        $job = new TrackLessonViewJob(userId: 424242, lessonId: 424243, courseId: 1);
        $job->handle(); // must NOT throw: a vanished row is not retryable

        self::assertSame(
            0,
            DB::table('activity_events')->where('event_type', ActivityEvent::TYPE_LESSON_OPEN)->count(),
            'vanished source must not fabricate an activity_events row (missing evidence ≠ a recorded view)',
        );
    }

    public function test_track_lesson_view_job_happy_path_stays_silent_and_records(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->for(Course::factory()->create())->create();

        Log::shouldReceive('warning')->never();

        (new TrackLessonViewJob(userId: $user->id, lessonId: $lesson->id, courseId: $lesson->course_id))->handle();

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();
        self::assertNotNull($view, 'happy path must still record the view');
        self::assertSame(1, $view->open_count);
        self::assertSame(
            1,
            DB::table('activity_events')->where('event_type', ActivityEvent::TYPE_LESSON_OPEN)->count(),
        );
        self::assertSame(1, (int) $user->fresh()->total_lessons_opened);
    }

    public function test_split_upload_listener_with_unarmed_disk_is_loud_not_supported(): void
    {
        config()->set('backup.backup.split_upload.disk', '');
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $ctx): bool => str_contains($message, 'off-site нога не вооружена')
                && $ctx['state'] === 'not_supported'
        );
        Log::shouldReceive('error')->never();

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful(diskName: 'local', backupName: 'app'));

        self::assertTrue(true, 'listener returned quietly otherwise: local backup stays untouched');
    }

    public function test_split_upload_listener_with_degenerate_local_disk_is_loud_not_supported(): void
    {
        config()->set('backup.backup.split_upload.disk', 'local');
        config()->set('backup.backup.split_upload.max_part_mb', 700);
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $ctx): bool => str_contains($message, 'off-site нога не вооружена')
                && $ctx['state'] === 'not_supported'
        );

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful(diskName: 'local', backupName: 'app'));
    }

    public function test_split_upload_resume_with_unarmed_disk_is_loud_not_supported(): void
    {
        config()->set('backup.backup.split_upload.disk', 'local');
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $ctx): bool => str_contains($message, 'докатка не вооружена')
                && $ctx['state'] === 'not_supported'
        );

        (new SplitUploadToYandex)->resumeOffsite();
    }

    /**
     * Sanity anchor: the request-triggered telemetry ingest (wave-2 census
     * seam) still returns 204 for unknown events — the deliberate API
     * tolerance documented on CabinetTelemetryController, NOT a missingness
     * violation (old tabs must not error).
     */
    public function test_cabinet_telemetry_unknown_event_tolerance_is_documented_and_intact(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('student.telemetry'), [
                'event' => 'not_a_known_event_name',
                'data' => ['x' => 1],
            ]);

        $response->assertStatus(204);
        self::assertSame(
            0,
            DB::table('activity_events')->where('event_type', 'not_a_known_event_name')->count(),
            'unknown event names are deliberately not persisted (API-version tolerance, not missingness)',
        );
    }
}
