<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccessDiagnosticsService;
use App\Services\BlockAccessMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedCourseWithBlocks(User $user, array $blockNumbers = [5, 6, 7]): array
    {
        $course = Course::factory()->create(['is_active' => true, 'slug' => 'access-ss-course']);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        foreach ($blockNumbers as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        $lessons = [];
        foreach ($blockNumbers as $n) {
            $lessons[$n] = Lesson::factory()->create([
                'course_id' => $course->id,
                'block_number' => $n,
                'is_published' => true,
                'is_preview' => false,
                'is_free' => false,
                'group_id' => null,
            ]);
        }

        return [$course, $lessons];
    }

    /** @test */
    public function diagnostics_flag_key_missing_when_paid_range_lacks_sibling_keys(): void
    {
        $user = User::factory()->create(['telegram_id' => '111']);
        [$course, $lessons] = $this->seedCourseWithBlocks($user);

        // Paid range 5–7 with only block_5 key, materializer NOT run (withoutEvents).
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9000,
            'tariff' => 'block_5',
            'status' => 'paid',
            'start_block' => 5,
            'end_block' => 7,
            'is_conditional' => false,
        ]));

        $owned = app(AccessDiagnosticsService::class)->ownedKeys($user, (int) $course->id);
        $this->assertSame(['block_5'], $owned);

        $findings = app(AccessDiagnosticsService::class)->forLesson(
            $user,
            $lessons[6],
            $course,
            $owned,
        );

        $codes = array_column($findings, 'code');
        $this->assertContains(AccessDiagnosticsService::CODE_KEY_MISSING, $codes);
        $primary = collect($findings)->firstWhere('code', AccessDiagnosticsService::CODE_KEY_MISSING);
        $this->assertSame(AccessDiagnosticsService::ACTION_MATERIALIZE, $primary['action']);
    }

    /** @test */
    public function diagnostics_flag_not_paid_when_no_covering_payment(): void
    {
        $user = User::factory()->create(['telegram_id' => '111']);
        [$course, $lessons] = $this->seedCourseWithBlocks($user, [1, 2]);

        $findings = app(AccessDiagnosticsService::class)->forLesson(
            $user,
            $lessons[2],
            $course,
            [],
        );

        $this->assertSame(
            AccessDiagnosticsService::CODE_NOT_PAID,
            $findings[0]['code'],
        );
        $this->assertSame(AccessDiagnosticsService::ACTION_PAY_DEBT, $findings[0]['action']);
    }

    /** @test */
    public function diagnostics_flag_payment_check_for_pending_payment(): void
    {
        $user = User::factory()->create(['telegram_id' => '111']);
        [$course, $lessons] = $this->seedCourseWithBlocks($user, [1, 2]);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4000,
            'tariff' => 'block_2',
            'status' => 'pending',
            'start_block' => 2,
            'end_block' => 2,
            'is_conditional' => false,
        ]));

        $findings = app(AccessDiagnosticsService::class)->forLesson(
            $user,
            $lessons[2],
            $course,
            [],
        );

        $this->assertSame(
            AccessDiagnosticsService::CODE_PAYMENT_CHECK,
            $findings[0]['code'],
        );
    }

    /** @test */
    public function diagnostics_flag_not_connected_bot_as_secondary_finding(): void
    {
        $user = User::factory()->create(['telegram_id' => null, 'vk_id' => null]);
        [$course, $lessons] = $this->seedCourseWithBlocks($user, [1]);

        $findings = app(AccessDiagnosticsService::class)->forLesson(
            $user,
            $lessons[1],
            $course,
            [],
        );

        $codes = array_column($findings, 'code');
        $this->assertContains(AccessDiagnosticsService::CODE_NOT_CONNECTED_BOT, $codes);
    }

    /** @test */
    public function materialize_endpoint_opens_paid_range_and_is_idempotent(): void
    {
        config(['features.access_self_service' => true]);

        $user = User::factory()->create();
        [$course, $lessons] = $this->seedCourseWithBlocks($user);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9000,
            'tariff' => 'block_5',
            'status' => 'paid',
            'start_block' => 5,
            'end_block' => 7,
            'is_conditional' => false,
        ]));

        $this->assertFalse($lessons[6]->isUnlockedBy(
            app(AccessDiagnosticsService::class)->ownedKeys($user, (int) $course->id)
        ));

        $this->actingAs($user)
            ->from(route('student.course', $course->slug))
            ->post(route('student.access.materialize', $course->slug))
            ->assertRedirect(route('student.course', $course->slug))
            ->assertSessionHas('access_status');

        $keys = app(AccessDiagnosticsService::class)->ownedKeys($user, (int) $course->id);
        $this->assertContains('block_6', $keys);
        $this->assertContains('block_7', $keys);
        $this->assertTrue($lessons[6]->fresh()->isUnlockedBy($keys));

        // Second call does not throw / does not double siblings.
        $before = Payment::where('transaction_id', 'like', BlockAccessMaterializer::GRANT_PREFIX.'%')->count();
        $this->actingAs($user)
            ->post(route('student.access.materialize', $course->slug))
            ->assertRedirect();
        $after = Payment::where('transaction_id', 'like', BlockAccessMaterializer::GRANT_PREFIX.'%')->count();
        $this->assertSame($before, $after);
    }

    /** @test */
    public function materialize_endpoint_404_when_flag_off(): void
    {
        config(['features.access_self_service' => false]);

        $user = User::factory()->create();
        [$course] = $this->seedCourseWithBlocks($user, [1]);

        $this->actingAs($user)
            ->post(route('student.access.materialize', $course->slug))
            ->assertNotFound();
    }

    /** @test */
    public function course_page_shows_why_locked_when_flag_on(): void
    {
        config(['features.access_self_service' => true]);

        $user = User::factory()->create();
        [$course, $lessons] = $this->seedCourseWithBlocks($user, [1, 2]);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 2,
            'is_conditional' => false,
        ]));

        $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk()
            ->assertSee('Почему закрыто?', false)
            ->assertSee('Открыть оплаченные блоки', false);
    }

    /** @test */
    public function course_page_hides_why_locked_when_flag_off(): void
    {
        config(['features.access_self_service' => false]);

        $user = User::factory()->create();
        [$course] = $this->seedCourseWithBlocks($user, [1, 2]);

        $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk()
            ->assertDontSee('Почему закрыто?', false);
    }

    /** @test */
    public function dashboard_shows_access_summary_when_flag_on(): void
    {
        config(['features.access_self_service' => true]);

        $user = User::factory()->create();
        [$course] = $this->seedCourseWithBlocks($user, [1]);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Мой доступ', false)
            ->assertSee('курс(ов)', false);
    }

    /** @test */
    public function profile_summary_includes_login_and_bot_findings(): void
    {
        $user = User::factory()->create(['telegram_id' => null, 'vk_id' => null]);
        $summary = app(AccessDiagnosticsService::class)->profileSummary($user, 2);

        $this->assertSame(2, $summary['courses_open']);
        $this->assertFalse($summary['bot_connected']);
        $this->assertTrue($summary['password_ok']);
        $codes = array_column($summary['findings'], 'code');
        $this->assertContains(AccessDiagnosticsService::CODE_NOT_CONNECTED_BOT, $codes);
        $this->assertContains(AccessDiagnosticsService::CODE_LOGIN_ISSUE, $codes);
    }
}
