<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\LessonView;
use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExtendFreeTierActivesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg',
        ]);
    }

    private function holder(?string $viewAt = null): int
    {
        $userId = User::factory()->create()->id;

        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id]);

        LessonAccessGrant::create([
            'user_id' => $userId,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'reason' => 'free_tier_h2566',
            'granted_at' => now()->subDays(6),
            'expires_at' => '2026-09-15 16:50:00',
        ]);

        if ($viewAt !== null) {
            LessonView::create([
                'user_id' => $userId,
                'lesson_id' => $lesson->id,
                'course_id' => $course->id,
                'first_opened_at' => $viewAt,
                'last_opened_at' => $viewAt,
                'open_count' => 1,
                'is_completed' => false,
            ]);
        }

        return $userId;
    }

    public function test_dry_run_reports_counts_and_writes_nothing(): void
    {
        $this->holder();
        $this->holder(now()->subDays(3)->toDateTimeString());

        $this->artisan('membership:extend-free-tier-actives', [
            '--until' => '2026-10-15',
            '--window' => '21',
        ])->assertSuccessful();

        $this->assertSame(
            '2026-09-15 16:50:00',
            (string) DB::table('lesson_access_grants')->value('expires_at'),
            'dry-run must not touch grants',
        );
    }

    public function test_zero_actives_is_a_clean_no_op(): void
    {
        $this->holder();

        $this->artisan('membership:extend-free-tier-actives', [
            '--until' => '2026-10-15',
        ])->assertSuccessful();

        $this->assertSame('2026-09-15 16:50:00', (string) DB::table('lesson_access_grants')->value('expires_at'));
    }

    public function test_apply_requires_until_date(): void
    {
        $this->holder();

        $this->artisan('membership:extend-free-tier-actives', ['--apply' => true])->assertFailed();
    }

    public function test_apply_extends_only_actives_to_until_date_and_rewrites_cohort_file(): void
    {
        $passive = $this->holder();
        $active = $this->holder(now()->subDays(3)->toDateTimeString());

        config(['membership.free_tier.cohort_file' => 'membership/test_cohort.txt']);

        $this->artisan('membership:extend-free-tier-actives', [
            '--until' => '2026-10-15',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(
            '2026-10-15 23:59:59',
            (string) DB::table('lesson_access_grants')->where('user_id', $active)->value('expires_at'),
        );
        $this->assertSame(
            '2026-09-15 16:50:00',
            (string) DB::table('lesson_access_grants')->where('user_id', $passive)->value('expires_at'),
            'passive holder must lapse on schedule',
        );

        $file = storage_path('app/membership/test_cohort.txt');
        $this->assertFileExists($file);
        $contents = file_get_contents($file);
        $this->assertStringContainsString('H2566', $contents);

        $idLines = collect(preg_split('/\r?\n/', trim($contents)))
            ->reject(fn (string $line) => $line === '' || str_starts_with(trim($line), '#'))
            ->map(fn (string $line) => trim($line))
            ->values();

        $this->assertSame([(string) $active], $idLines->all(), 'cohort file must list exactly the actives');
    }
}
