<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\OrphanPayments;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrphanPaymentsReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3913: отчёт «платежи без привязки к студенту» (сиротские платежи) —
 * read-only список оплат, записанных от аккаунта супер-админа (в этой схеме
 * payments.user_id NOT NULL — «без user_id» физически не бывает), с подсказкой
 * кандидата (сумма + близкая дата среди учеников курса). Реальный кейс:
 * 3 счёта по 8000 ₽ за блок 1 Летнего интенсива от SuperAdmin.
 */
class OrphanPaymentsReportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Course $otherCourse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = Course::factory()->create(['title' => 'Летний интенсив']);
        $this->otherCourse = Course::factory()->create(['title' => 'Другой курс']);
        app(OrphanPaymentsReport::class)->flushCandidateCache();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => Roles::SUPER_ADMIN, 'name' => 'Супер-админ']);
    }

    private function studentOf(Course $course, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $course->users()->attach($user->id);

        return $user;
    }

    private function pay(array $attrs): Payment
    {
        return Payment::create(array_merge([
            'course_id' => $this->course->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'created_at' => '2026-08-06 10:00:00',
        ], $attrs));
    }

    /** @test */
    public function lists_only_orphan_paid_payments(): void
    {
        $superAdmin = $this->superAdmin();

        $fromSuperAdmin1 = $this->pay(['user_id' => $superAdmin->id, 'created_at' => '2026-08-06 10:00:00']);
        $fromSuperAdmin2 = $this->pay(['user_id' => $superAdmin->id, 'created_at' => '2026-08-13 12:00:00']);

        // Всё это НЕ сироты и не должно показываться.
        $student = $this->studentOf($this->course);
        $this->pay(['user_id' => $student->id]);
        $otherAdmin = $this->superAdmin();
        $this->pay(['user_id' => $otherAdmin->id, 'is_conditional' => true]); // аванс под обещание
        $this->pay(['user_id' => $otherAdmin->id, 'status' => 'canceled']);
        $this->pay(['user_id' => $otherAdmin->id, 'status' => 'pending']);

        $rows = app(OrphanPaymentsReport::class)->query()->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn (Payment $p) => $p->is($fromSuperAdmin1)));
        $this->assertTrue($rows->contains(fn (Payment $p) => $p->is($fromSuperAdmin2)));
    }

    /** @test */
    public function candidate_hint_ranks_same_amount_students_by_date_proximity(): void
    {
        $this->pay(['user_id' => $this->superAdmin()->id, 'created_at' => '2026-08-06 10:00:00']); // сирота

        $near = $this->studentOf($this->course, ['name' => 'Настя']);
        $this->pay(['user_id' => $near->id, 'created_at' => '2026-08-08 10:00:00']); // Δ2 дня

        $far = $this->studentOf($this->course, ['name' => 'Ольга']);
        $this->pay(['user_id' => $far->id, 'created_at' => '2026-06-20 10:00:00']); // та же сумма, далеко

        // Чужой курс и другая сумма — не кандидаты.
        $outsider = $this->studentOf($this->otherCourse);
        $this->pay(['user_id' => $outsider->id, 'course_id' => $this->otherCourse->id, 'created_at' => '2026-08-07 10:00:00']);
        $this->pay(['user_id' => $near->id, 'amount' => 5000, 'created_at' => '2026-08-07 10:00:00']);

        $orphan = app(OrphanPaymentsReport::class)->query()->first();
        $candidates = app(OrphanPaymentsReport::class)->candidatesFor($orphan);

        $this->assertCount(2, $candidates);
        $this->assertSame('Настя', $candidates[0]['name']);
        $this->assertTrue($candidates[0]['near']);
        $this->assertSame(2, $candidates[0]['diff_days']);
        $this->assertSame('Ольга', $candidates[1]['name']);
        $this->assertFalse($candidates[1]['near']);
    }

    /** @test */
    public function label_is_human_readable(): void
    {
        $this->pay(['user_id' => $this->superAdmin()->id, 'created_at' => '2026-08-06 10:00:00']);

        $student = $this->studentOf($this->course, ['name' => 'Настя']);
        $this->pay(['user_id' => $student->id, 'created_at' => '2026-08-08 10:00:00']);

        $orphan = app(OrphanPaymentsReport::class)->query()->first();
        $label = app(OrphanPaymentsReport::class)->candidateLabel($orphan);

        $this->assertStringContainsString('Настя', $label);
        $this->assertStringContainsString('08.08.2026', $label);
        $this->assertStringContainsString('Δ2 дн', $label);
    }

    /** @test */
    public function payment_without_course_has_no_candidates(): void
    {
        $this->pay(['user_id' => $this->superAdmin()->id, 'course_id' => null]);

        $orphan = app(OrphanPaymentsReport::class)->query()->first();

        $this->assertSame([], app(OrphanPaymentsReport::class)->candidatesFor($orphan));
        $this->assertNull(app(OrphanPaymentsReport::class)->candidateLabel($orphan));
    }

    /** @test */
    public function the_page_renders_for_admin_and_is_gated_like_debtors(): void
    {
        $this->pay(['user_id' => $this->superAdmin()->id, 'created_at' => '2026-08-06 10:00:00']);

        $student = $this->studentOf($this->course, ['name' => 'Настя']);
        $this->pay(['user_id' => $student->id, 'created_at' => '2026-08-08 10:00:00']);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $this->assertTrue(OrphanPayments::canAccess());
        $this->assertTrue(OrphanPayments::shouldRegisterNavigation());

        $this->get('/admin/orphan-payments')
            ->assertSuccessful()
            ->assertSee('Сиротские платежи')
            ->assertSee('8 000 ₽')
            ->assertSee('Настя');
    }

    /** @test */
    public function non_admin_roles_are_denied(): void
    {
        foreach ([Roles::MANAGER, Roles::TEACHER, Roles::ACCOUNTANT, Roles::SUPER_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));

            if ($role === Roles::SUPER_ADMIN) {
                $this->assertTrue(
                    OrphanPayments::canAccess(),
                    "роль {$role} (super_admin) обязана проходить гейт",
                );

                continue;
            }

            $this->assertFalse(
                OrphanPayments::canAccess(),
                "роль {$role} не должна проходить гейт",
            );
            $this->get('/admin/orphan-payments')->assertForbidden();
        }
    }

    /** @test */
    public function the_report_writes_nothing(): void
    {
        $this->pay(['user_id' => $this->superAdmin()->id]);
        $student = $this->studentOf($this->course);
        $this->pay(['user_id' => $student->id]);

        $report = app(OrphanPaymentsReport::class);
        $before = Payment::count();

        foreach ($report->query()->get() as $orphan) {
            $report->candidatesFor($orphan);
            $report->candidateLabel($orphan);
        }
        $report->courseTitles();

        $this->assertSame($before, Payment::count());
        $orphan = $report->query()->first();
        $this->assertSame(Roles::SUPER_ADMIN, $orphan->user->role);
    }
}
