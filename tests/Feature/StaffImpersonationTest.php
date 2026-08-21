<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherSalaries;
use App\Filament\Resources\ImpersonationAuditResource;
use App\Models\ImpersonationAudit;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\RoleGate;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Режим просмотра за пользователя (H1947): кто может войти, что в режиме
 * запрещено, что пишется в журнал и как из режима выйти.
 */
class StaffImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.staff_impersonation' => true]);
    }

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function start(User $target, string $mode): TestResponse
    {
        return $this->get(Impersonation::startUrl($target, $mode));
    }

    // ==========================================
    // ФЛАГ
    // ==========================================

    /** @test */
    public function flag_off_hides_actions_and_404s_the_start_route(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $this->actingAs($super);

        $url = Impersonation::startUrl($student, Impersonation::MODE_STUDENT);

        config(['features.staff_impersonation' => false]);

        $this->assertFalse(Impersonation::canImpersonate($student, Impersonation::MODE_STUDENT));
        $this->assertFalse(Impersonation::canImpersonate($student, Impersonation::MODE_MANAGER));
        $this->assertFalse(ImpersonationAuditResource::canViewAny());

        $this->get($url)->assertNotFound();
        $this->assertDatabaseCount('impersonation_audits', 0);
    }

    /** @test */
    public function flag_switched_off_mid_session_closes_the_mode_on_the_next_request(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $this->actingAs($super);
        $this->start($student, Impersonation::MODE_STUDENT);
        $this->assertAuthenticatedAs($student);

        config(['features.staff_impersonation' => false]);

        $this->get('/')->assertRedirect('/admin');

        $this->assertAuthenticatedAs($super);
        $this->assertFalse(Impersonation::isActive());
        $this->assertNotNull(ImpersonationAudit::query()->sole()->stopped_at);
    }

    // ==========================================
    // КТО МОЖЕТ НАЧАТЬ
    // ==========================================

    /** @test */
    public function ordinary_admin_cannot_start_the_mode(): void
    {
        $admin = $this->user(Roles::ADMIN);
        $student = $this->user(null);

        $this->actingAs($admin);

        $this->assertFalse(Impersonation::canStart());
        $this->assertFalse(Impersonation::canImpersonate($student, Impersonation::MODE_STUDENT));

        // Ссылку подписывает супер-админ — но админ по ней всё равно не пройдёт.
        $this->actingAs($this->user(Roles::SUPER_ADMIN));
        $url = Impersonation::startUrl($student, Impersonation::MODE_STUDENT);

        $this->actingAs($admin);
        $this->get($url)->assertForbidden();

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseCount('impersonation_audits', 0);
    }

    /** @test */
    public function another_super_admin_can_never_be_impersonated(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $otherSuper = $this->user(Roles::SUPER_ADMIN);

        $this->actingAs($super);

        $this->assertFalse(Impersonation::canImpersonate($otherSuper, Impersonation::MODE_STUDENT));
        $this->assertFalse(Impersonation::canImpersonate($otherSuper, Impersonation::MODE_MANAGER));

        $this->get(Impersonation::startUrl($otherSuper, Impersonation::MODE_MANAGER))->assertForbidden();
        $this->assertAuthenticatedAs($super);
    }

    /** @test */
    public function only_manager_qualifies_for_manager_mode_and_only_roleless_for_student_mode(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $this->actingAs($super);

        $student = $this->user(null);
        $manager = $this->user(Roles::MANAGER);
        $teacher = $this->user(Roles::TEACHER);

        $this->assertTrue(Impersonation::canImpersonate($student, Impersonation::MODE_STUDENT));
        $this->assertFalse(Impersonation::canImpersonate($student, Impersonation::MODE_MANAGER));

        $this->assertTrue(Impersonation::canImpersonate($manager, Impersonation::MODE_MANAGER));
        $this->assertFalse(Impersonation::canImpersonate($manager, Impersonation::MODE_STUDENT));

        $this->assertFalse(Impersonation::canImpersonate($teacher, Impersonation::MODE_MANAGER));
        $this->assertFalse(Impersonation::canImpersonate($teacher, Impersonation::MODE_STUDENT));
        $this->assertTrue(Impersonation::canImpersonate($teacher, Impersonation::MODE_TEACHER));
        $this->assertFalse(Impersonation::canImpersonate($student, Impersonation::MODE_TEACHER));
        $this->assertFalse(Impersonation::canImpersonate($manager, Impersonation::MODE_TEACHER));

        $this->assertFalse(Impersonation::canImpersonate($super, Impersonation::MODE_STUDENT), 'сам под себя');
    }

    /** @test */
    public function the_mode_cannot_be_nested(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $manager = $this->user(Roles::MANAGER);
        $student = $this->user(null);

        $this->actingAs($super);
        $nestedUrl = Impersonation::startUrl($student, Impersonation::MODE_STUDENT);

        $this->start($manager, Impersonation::MODE_MANAGER);
        $this->assertAuthenticatedAs($manager);

        $this->assertFalse(Impersonation::canStart());
        $this->get($nestedUrl)->assertForbidden();

        $this->assertAuthenticatedAs($manager);
        $this->assertDatabaseCount('impersonation_audits', 1);
    }

    // ==========================================
    // РЕЖИМ СТУДЕНТА
    // ==========================================

    /** @test */
    public function super_admin_lands_in_the_cabinet_as_the_student(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $this->actingAs($super);

        $this->start($student, Impersonation::MODE_STUDENT)
            ->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($student);
        $this->assertTrue(Impersonation::isActive());
        $this->assertSame(Impersonation::MODE_STUDENT, Impersonation::mode());
        $this->assertTrue(Impersonation::impersonator()?->is($super));
    }

    /** @test */
    public function student_mode_does_not_open_the_filament_panel(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $this->actingAs($super);
        $this->start($student, Impersonation::MODE_STUDENT);

        $this->get('/admin')->assertForbidden();
    }

    /** @test */
    public function the_banner_is_injected_into_rendered_pages(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $this->actingAs($super);
        $this->start($student, Impersonation::MODE_STUDENT);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="impersonation-banner"', false)
            ->assertSee($student->name)
            ->assertSee('Выйти из режима');
    }

    /** @test */
    public function impersonating_a_student_does_not_forge_their_login_or_activity(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $before = $student->only(['login_count', 'last_login_at', 'last_activity_at']);

        $this->actingAs($super);
        $this->start($student, Impersonation::MODE_STUDENT);
        $this->get('/');

        $fresh = $student->fresh();

        $this->assertSame((int) $before['login_count'], (int) $fresh->login_count);
        $this->assertEquals($before['last_login_at'], $fresh->last_login_at);
        $this->assertEquals($before['last_activity_at'], $fresh->last_activity_at);
        $this->assertDatabaseCount('user_sessions', 0);
    }

    // ==========================================
    // РЕЖИМ КУРАТОРА
    // ==========================================

    /** @test */
    public function manager_mode_drops_super_admin_powers(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $manager = $this->user(Roles::MANAGER);

        $this->actingAs($super);
        $this->assertTrue(RoleGate::isSuperAdmin());

        $this->start($manager, Impersonation::MODE_MANAGER)->assertRedirect('/admin');

        $this->assertAuthenticatedAs($manager);

        // Главное требование хэндоффа: под куратором супер-админского обхода нет.
        $this->assertFalse(RoleGate::isSuperAdmin());
        $this->assertFalse(RoleGate::adminOnly(), 'админ-онли действия');
        $this->assertFalse(RoleGate::accounting(), 'зарплатный контур');
        $this->assertFalse(RoleGate::finance(), 'финотчёты');
        $this->assertFalse(ImpersonationAuditResource::canViewAny(), 'журнал режима');
        $this->assertFalse(auth()->user()->isSuperAdmin());
    }

    /** @test */
    public function teacher_mode_opens_admin_as_that_teacher(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $card = Teacher::create(['name' => 'Екатерина Костина']);
        $teacher = User::factory()->create([
            'role' => Roles::TEACHER,
            'teacher_id' => $card->id,
        ]);

        $this->actingAs($super);
        $this->start($teacher, Impersonation::MODE_TEACHER)->assertRedirect('/admin');

        $this->assertAuthenticatedAs($teacher);
        $this->assertSame(Impersonation::MODE_TEACHER, Impersonation::mode());
        $this->assertFalse(RoleGate::isSuperAdmin());
        $this->assertFalse(RoleGate::accounting(), 'школьная ведомость бухгалтера закрыта');
        $this->assertTrue(RoleGate::seesOwnSalary(), 'свой расчёт ЗП в режиме преподавателя открыт');
        $this->assertTrue(TeacherSalaries::canAccess());
        $this->assertTrue(auth()->user()->isTeacher());
    }

    /**
     * Плашка обязана быть и в панели: у Filament свой стек middleware, группу
     * `web` он не включает — без отдельной регистрации гварда куратор остался бы
     * без единственной кнопки выхода.
     *
     * @test
     */
    public function the_banner_reaches_the_filament_panel_too(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $manager = $this->user(Roles::MANAGER);

        $this->actingAs($super);
        $this->start($manager, Impersonation::MODE_MANAGER);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('id="impersonation-banner"', false)
            ->assertSee('Вернуться в супер-админ');
    }

    // ==========================================
    // ДЕНЬГИ
    // ==========================================

    /** @test */
    public function money_writes_are_blocked_while_impersonating(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $this->actingAs($super);
        $this->start($student, Impersonation::MODE_STUDENT);

        $this->post('/payment/create', [])->assertForbidden();
        $this->post('/deposit/whatever', [])->assertForbidden();

        // Чтение денежных страниц режим не трогает — иначе смотреть было бы не на что.
        $this->get('/')->assertOk();
    }

    /** @test */
    public function money_writes_work_normally_outside_the_mode(): void
    {
        $student = $this->user(null);

        $this->actingAs($student);

        // Не 403: маршрут отвечает своей обычной валидацией/редиректом.
        $this->post('/payment/create', [])->assertStatus(302);
    }

    // ==========================================
    // ВЫХОД И ЖУРНАЛ
    // ==========================================

    /** @test */
    public function stopping_restores_the_super_admin_and_closes_the_audit_row(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $manager = $this->user(Roles::MANAGER);

        $this->actingAs($super);
        $this->start($manager, Impersonation::MODE_MANAGER);

        $audit = ImpersonationAudit::query()->sole();
        $this->assertSame($super->id, $audit->impersonator_id);
        $this->assertSame($manager->id, $audit->target_id);
        $this->assertSame(Impersonation::MODE_MANAGER, $audit->mode);
        $this->assertNotNull($audit->started_at);
        $this->assertNull($audit->stopped_at, 'открытый режим');

        $this->post(route('impersonate.stop'))->assertRedirect('/admin/users');

        $this->assertAuthenticatedAs($super);
        $this->assertFalse(Impersonation::isActive());
        $this->assertTrue(RoleGate::isSuperAdmin());
        $this->assertNotNull($audit->fresh()->stopped_at);
    }

    /** @test */
    public function stopping_outside_the_mode_is_a_404(): void
    {
        $this->actingAs($this->user(Roles::SUPER_ADMIN));

        $this->post(route('impersonate.stop'))->assertNotFound();
    }

    /** @test */
    public function an_unsigned_start_link_is_rejected(): void
    {
        $super = $this->user(Roles::SUPER_ADMIN);
        $student = $this->user(null);

        $this->actingAs($super);

        $this->get('/impersonate/'.$student->id.'/student')->assertForbidden();
        $this->assertAuthenticatedAs($super);
        $this->assertDatabaseCount('impersonation_audits', 0);
    }
}
