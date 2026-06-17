<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherSalaries;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\TeacherPayoutResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Доступы роли «Бухгалтер»: выплаты/зарплаты — только бухгалтер и супер-админ
 * (обычный admin БОЛЬШЕ не видит), финансы и студенты — read-only.
 */
class AccountantAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @test */
    public function accountant_can_access_payouts_salaries_finance_and_students(): void
    {
        $this->actingAs($this->user(Roles::ACCOUNTANT));

        $this->assertTrue(TeacherPayoutResource::canViewAny(), 'История выплат');
        $this->assertTrue(TeacherSalaries::canAccess(), 'Дашборд Зарплаты');
        $this->assertTrue(PaymentResource::canViewAny(), 'Финансы');
        $this->assertTrue(UserResource::canViewAny(), 'Студенты (список)');
    }

    /** @test */
    public function accountant_has_students_read_only_without_mutating_actions(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);
        $student = $this->user(null);

        $this->actingAs($accountant);

        // Видит, но не создаёт/не правит/не удаляет.
        $this->assertTrue(UserResource::canView($student));
        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(UserResource::canEdit($student));
        $this->assertFalse(UserResource::canDeleteAny(), 'массовое удаление студентов');

        // Гейт, которым закрыты send_password / send_bulk_access в таблице.
        $this->assertFalse(RoleGate::adminOnly());
    }

    /** @test */
    public function regular_admin_loses_access_to_payouts_and_salaries(): void
    {
        $this->actingAs($this->user(Roles::ADMIN));

        // Регрессия: раньше admin видел выплаты — теперь нет.
        $this->assertFalse(TeacherPayoutResource::canViewAny());
        $this->assertFalse(TeacherSalaries::canAccess());

        // Финансы и студенты у admin остаются.
        $this->assertTrue(PaymentResource::canViewAny());
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(UserResource::canDeleteAny());
    }

    /** @test */
    public function super_admin_keeps_full_access(): void
    {
        $this->actingAs($this->user(Roles::SUPER_ADMIN));

        $this->assertTrue(TeacherPayoutResource::canViewAny());
        $this->assertTrue(TeacherSalaries::canAccess());
        $this->assertTrue(PaymentResource::canViewAny());
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(UserResource::canDeleteAny());
    }

    /** @test */
    public function manager_and_student_cannot_reach_payouts(): void
    {
        $this->actingAs($this->user(Roles::MANAGER));
        $this->assertFalse(TeacherPayoutResource::canViewAny());
        $this->assertTrue(PaymentResource::canViewAny(), 'менеджер видит финансы');

        $this->actingAs($this->user(null));
        $this->assertFalse(TeacherPayoutResource::canViewAny());
        $this->assertFalse(PaymentResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
    }

    /** @test */
    public function accountant_can_enter_admin_panel(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);

        $this->assertTrue($accountant->canAccessPanel(Filament::getPanel('admin')));
    }
}
