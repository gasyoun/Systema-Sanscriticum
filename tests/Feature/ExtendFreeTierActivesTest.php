<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\LessonView;
use App\Models\MarketingSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExtendFreeTierActivesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Единственный источник правды для фикстуры и ассертов. Раньше значение
     * было размазано литералами, а now() не был заморожен — и тест был
     * time-bomb: команда продлевает только гранты с expires_at > now(),
     * поэтому после 16:50 MSK своего дня (с учётом app.timezone =
     * Europe/Moscow) фикстурный грант считался протухшим и тест краснел
     * навсегда (CI e47e5187, PR #2591 — 15-09-2026).
     */
    private const FIXTURE_EXPIRES_AT = '2026-09-15 16:50:00';

    protected function setUp(): void
    {
        parent::setUp();
        // Замораживаем now() на 4 часа ДО фикстурного expires_at: грант живой,
        // «активный» определяется детерминированно, независимо от того, когда
        // CI исполняет тест.
        Carbon::setTestNow(Carbon::parse(self::FIXTURE_EXPIRES_AT)->subHours(4));

        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
            'expires_at' => self::FIXTURE_EXPIRES_AT,
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
            self::FIXTURE_EXPIRES_AT,
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

        $this->assertSame(self::FIXTURE_EXPIRES_AT, (string) DB::table('lesson_access_grants')->value('expires_at'));
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
            self::FIXTURE_EXPIRES_AT,
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
