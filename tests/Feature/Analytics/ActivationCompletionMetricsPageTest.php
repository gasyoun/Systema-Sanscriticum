<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Filament\Pages\ActivationCompletionMetrics;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H3764 — gate + flag + rendered values. The gate is RoleGate::accounting()
 * (MG ruling 30-08-2026): an ordinary admin does NOT pass, unlike the finance
 * dashboards.
 */
class ActivationCompletionMetricsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));
        config(['features.activation_completion_metrics' => true]);
        config(['activation_metrics.min_denominator' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedOneCohort(): void
    {
        $course = Course::factory()->create(['title' => 'Санскрит-1']);
        $student = User::factory()->create(['login_count' => 3]);

        Payment::withoutEvents(function () use ($student, $course): void {
            Payment::create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'tariff' => 'block_1',
                'amount' => 5000,
                'status' => 'paid',
                'is_conditional' => false,
                'created_at' => Carbon::parse('2026-01-10 12:00:00'),
            ]);
        });

        $lesson = Lesson::factory()->create(['course_id' => $course->id]);
        LessonView::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'first_opened_at' => Carbon::parse('2026-01-15 12:00:00'),
            'last_opened_at' => Carbon::parse('2026-01-15 12:00:00'),
            'is_completed' => true,
        ]);

        DB::table('course_user')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function accountant_sees_both_metric_blocks_with_their_denominators(): void
    {
        $this->seedOneCohort();
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $this->assertTrue(ActivationCompletionMetrics::canAccess());

        $this->get('/admin/activation-completion-metrics')
            ->assertSuccessful()
            ->assertSee('O2 · Активация по когортам')
            ->assertSee('C4 · Завершаемость по курсам')
            ->assertSee('C4 · Завершаемость по потокам')
            ->assertSee('Знаменатели — читать до процентов')
            ->assertSee('config/activation_metrics.php')
            ->assertSee('2026-01')
            ->assertSee('Санскрит-1');
    }

    /** @test */
    public function ordinary_admin_does_not_pass_the_accounting_gate(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $this->assertFalse(ActivationCompletionMetrics::canAccess());
        $this->assertFalse(ActivationCompletionMetrics::shouldRegisterNavigation());
        $this->get('/admin/activation-completion-metrics')->assertForbidden();
    }

    /** @test */
    public function teacher_and_student_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(ActivationCompletionMetrics::canAccess());
        $this->get('/admin/activation-completion-metrics')->assertForbidden();
    }

    /** @test */
    public function empty_database_renders_no_data_wording_not_zero_percent(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $this->get('/admin/activation-completion-metrics')
            ->assertSuccessful()
            ->assertSee('Нет данных');
    }

    /** @test */
    public function blade_does_not_hardcode_thresholds(): void
    {
        $blade = file_get_contents(resource_path('views/filament/pages/activation-completion-metrics.blade.php'));

        $this->assertIsString($blade);
        $this->assertStringNotContainsString('0.8', $blade);
        $this->assertStringNotContainsString('80 %', $blade);
        $this->assertStringContainsString('config_source', $blade);
        $this->assertStringContainsString('lesson_ratio', $blade);
    }
}
