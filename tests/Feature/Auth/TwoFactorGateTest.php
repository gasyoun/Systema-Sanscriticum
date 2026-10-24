<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\Roles;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * H6352-r5 (рулинг 7 грилла 10-10): админ-роли (super_admin/admin/manager/
 * accountant) не проходят дальше страницы 2FA, пока не докажут второй фактор;
 * преподаватель и студент не гейтятся. Проверяем подключение, вход по коду,
 * одноразовость резервного кода и реальный маршрут панели.
 */
class TwoFactorGateTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ROUTE = '/__test/panel';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.two_factor_enforced', true);

        // Лёгкий аналог страницы панели: тот же стек `web` (в нём живёт гейт) + auth.
        Route::middleware(['web', 'auth'])->get(self::TEST_ROUTE, fn () => 'panel');
    }

    private function staff(string $role = Roles::ADMIN): User
    {
        return User::factory()->create([
            'role' => $role,
            'email' => $role.'@example.com',
        ]);
    }

    private function enrol(User $user, string $secret, array $plainRecoveryCodes = []): void
    {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(
                fn (string $code): string => Hash::make($code),
                $plainRecoveryCodes,
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_admin_without_second_factor_is_redirected_to_the_gate(): void
    {
        $admin = $this->staff(Roles::ADMIN);

        $this->actingAs($admin)
            ->get(self::TEST_ROUTE)
            ->assertRedirect(route('two-factor.show'));
    }

    public function test_every_admin_role_is_gated_but_teacher_is_not(): void
    {
        foreach ([Roles::SUPER_ADMIN, Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT] as $role) {
            $this->actingAs($this->staff($role))
                ->get(self::TEST_ROUTE)
                ->assertRedirect(route('two-factor.show'));
        }

        $teacher = User::factory()->create(['role' => Roles::TEACHER, 'email' => 't@example.com']);
        $this->actingAs($teacher)->get(self::TEST_ROUTE)->assertOk();
    }

    public function test_enrollment_flow_issues_recovery_codes_and_passes_the_gate(): void
    {
        $admin = $this->staff();

        // 1. Страница подключения показывает секрет и кладёт его в сессию.
        $this->actingAs($admin)->get(route('two-factor.show'))->assertOk()->assertSee('Подключите двухфакторную');

        $secret = (string) session(TwoFactorService::SESSION_ENROLLING);
        $this->assertNotSame('', $secret);

        // 2. Неверный код не подтверждает подключение.
        $this->post(route('two-factor.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, User::query()->whereNotNull('two_factor_secret')->count());

        // 3. Верный код включает 2FA, показывает резервные коды и пропускает в панель.
        $response = $this->post(route('two-factor.verify'), ['code' => Totp::code($secret)]);

        $response->assertOk()->assertSee('Резервные коды');
        $admin->refresh();
        $this->assertTrue($admin->hasTwoFactorEnabled());
        $this->assertCount(TwoFactorService::RECOVERY_COUNT, $admin->two_factor_recovery_codes);

        $this->get(self::TEST_ROUTE)->assertOk();
    }

    public function test_enrolled_admin_challenges_every_new_session_and_passes_with_a_code(): void
    {
        $admin = $this->staff();
        $secret = Totp::randomSecret();
        $this->enrol($admin, $secret);

        $this->actingAs($admin)
            ->get(self::TEST_ROUTE)
            ->assertRedirect(route('two-factor.show'));

        $this->get(route('two-factor.show'))->assertOk()->assertSee('Подтвердите вход');

        $this->post(route('two-factor.verify'), ['code' => Totp::code($secret)])
            ->assertRedirect('/admin');

        $this->get(self::TEST_ROUTE)->assertOk();
    }

    public function test_recovery_code_is_single_use(): void
    {
        $admin = $this->staff();
        $this->enrol($admin, Totp::randomSecret(), ['AAAA-BBBB', 'CCCC-DDDD']);

        $this->actingAs($admin)->get(route('two-factor.show'));

        $this->post(route('two-factor.verify'), ['code' => 'aaaa-bbbb'])
            ->assertRedirect('/admin');

        $admin->refresh();
        $this->assertCount(1, $admin->two_factor_recovery_codes);

        // Вторая попытка тем же кодом — уже неверный (код израсходован).
        auth()->logout();
        $this->actingAs($admin)->post(route('two-factor.verify'), ['code' => 'AAAA-BBBB'])
            ->assertSessionHasErrors('code');
    }

    public function test_real_panel_route_hits_the_same_gate(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect(route('two-factor.show'));
    }

    public function test_enforcement_switch_releases_the_gate(): void
    {
        config()->set('security.two_factor_enforced', false);

        $admin = $this->staff();

        $this->actingAs($admin)->get(self::TEST_ROUTE)->assertOk();
    }

    public function test_reset_command_clears_the_second_factor(): void
    {
        $admin = $this->staff();
        $this->enrol($admin, Totp::randomSecret(), ['AAAA-BBBB']);

        $this->artisan('two-factor:reset', ['email' => $admin->email, '--force' => true])
            ->assertSuccessful();

        $admin->refresh();
        $this->assertFalse($admin->hasTwoFactorEnabled());
    }
}
