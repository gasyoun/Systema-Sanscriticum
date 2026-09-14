<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\RelationManagers\CourseAccessWindowsRelationManager;
use App\Models\Course;
use App\Models\CourseAccessWindow;
use App\Models\Payment;
use App\Models\User;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H4468 — Filament-UI окон доступа (v1.1 H4456).
 *
 * Решения MG 09-09-2026: «Пакет 30 дней» · «Filament-кнопка (v1.1)» — куратор
 * (manager) выдаёт окно студенту в админке; логика скоупа/покупки — в
 * CourseAccessWindow::grantInScope (её и гоняем напрямую), UI — HTTP-гейтами
 * (manager видит кнопку «Выдать окно», учитель — нет). Платежи не трогаются.
 */
class CourseAccessWindowsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $manager;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();

        $this->student = User::factory()->create();
        $this->manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->course = Course::factory()->create();

        config()->set('access_window.enabled_course_ids', [$this->course->id]);

        Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => 5500,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => false,
        ]);
    }

    /** @test */
    public function grant_in_scope_creates_an_active_window(): void
    {
        $result = CourseAccessWindow::grantInScope(
            $this->student,
            $this->course->id,
            now()->addDays(30),
            'по запросу студента, пакет 30 дней',
            $this->manager->id,
        );

        $this->assertTrue($result['ok']);
        $window = $result['window'];
        $this->assertSame($this->student->id, $window->user_id);
        $this->assertSame($this->course->id, $window->course_id);
        $this->assertTrue($window->isActive());
        $this->assertSame($this->manager->id, $window->created_by);
    }

    /** @test */
    public function grant_for_a_course_the_student_has_not_bought_is_rejected(): void
    {
        $notBought = Course::factory()->create();
        config()->set('access_window.enabled_course_ids', [$this->course->id, $notBought->id]);

        $result = CourseAccessWindow::grantInScope($this->student, $notBought->id, now()->addDays(30), 'x');

        $this->assertFalse($result['ok']);
        $this->assertSame('course_not_purchased', $result['error']);
        $this->assertSame(0, CourseAccessWindow::query()->count());
    }

    /** @test */
    public function grant_outside_the_configured_scope_is_rejected_even_if_purchased(): void
    {
        config()->set('access_window.enabled_course_ids', [999999]);

        $result = CourseAccessWindow::grantInScope($this->student, $this->course->id, now()->addDays(30), 'вне скоупа');

        $this->assertFalse($result['ok']);
        $this->assertSame('course_out_of_scope', $result['error']);
        $this->assertSame(0, CourseAccessWindow::query()->count());
    }

    /** @test */
    public function forever_window_is_created_without_ends_at(): void
    {
        $result = CourseAccessWindow::grantInScope(
            $this->student,
            $this->course->id,
            null,
            'именное исключение по слову MG 09-09',
        );

        $this->assertTrue($result['ok']);
        $this->assertNull($result['window']->ends_at);
        $this->assertTrue($result['window']->isActive());
    }

    /** @test */
    public function manager_sees_the_windows_table_with_records(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $window = CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->addDays(30), 'по запросу студента, пакет 30 дней');

        Livewire::actingAs($this->manager)
            ->test(CourseAccessWindowsRelationManager::class, [
                'ownerRecord' => $this->student->fresh(),
                'pageClass' => ViewUser::class,
            ])
            ->assertOk()
            ->assertCanSeeTableRecords([$window]);
    }

    /** @test */
    public function teacher_cannot_even_reach_the_user_card(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);

        $this->actingAs($teacher)
            ->get(ViewUser::getUrl(['record' => $this->student->getRouteKey()]))
            ->assertForbidden();

        // Роль-гейт кнопок выдачи/снятия: teacher не входит в ADMIN+SUPER_ADMIN+MANAGER.
        $this->assertFalse(RoleGate::any(Roles::ADMIN, Roles::SUPER_ADMIN, Roles::MANAGER));
    }

    /** @test */
    public function clearing_removes_the_window(): void
    {
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->addDays(30), 'тест');

        $this->assertSame(1, CourseAccessWindow::clearFor($this->student->id, $this->course->id));
        $this->assertSame(0, CourseAccessWindow::query()->count());
    }

    /** @test */
    public function the_relation_manager_is_registered_on_user_resource(): void
    {
        $this->assertContains(
            CourseAccessWindowsRelationManager::class,
            UserResource::getRelations(),
        );
    }
}
