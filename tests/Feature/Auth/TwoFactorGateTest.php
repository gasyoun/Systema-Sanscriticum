<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\LectureDraft;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\Roles;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H6352-r7 (рулинг MG 07-11-2026): 2FA гейтит только Filament-панели
 * (/admin, /editor) — поверхности изменения данных. Публичные страницы и
 * кабинет для админ-роли открываются без второго фактора: RequireTwoFactor
 * снят с группы `web` и живёт только в стеках панелей. Преподаватель и
 * студент не гейтятся нигде. Проверяем подключение, вход по коду,
 * одноразовость резервного кода, реальные маршруты панелей и то, что
 * контент сайта не запирается.
 *
 * H7830 (08-11-2026): поверхности изменения данных, зарегистрированные вне
 * стеков панелей, снова под гейтом — POST /editor/lectures/{draft}/patch
 * гейтится прямо на маршруте; Livewire-обновления панельных компонентов
 * повторно прогоняют гейт через persistent-middleware.
 */
class TwoFactorGateTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ROUTE = '/__test/panel';

    private const WEB_ROUTE = '/__test/web';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.two_factor_enforced', true);

        // Лёгкий аналог страницы панели: группа web (сессия) + auth + гейт —
        // реальная панель несёт StartSession в собственном стеке middleware.
        Route::middleware(['web', 'auth', RequireTwoFactor::class])
            ->get(self::TEST_ROUTE, fn () => 'panel');

        // Лёгкий аналог публичной страницы/кабинета: группа `web` без гейта.
        Route::middleware(['web', 'auth'])->get(self::WEB_ROUTE, fn () => 'content');
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

    public function test_web_content_is_not_gated_for_admin_role(): void
    {
        // Подключённая 2FA без пройденного челленджа — контент сайта всё равно открывается.
        $admin = $this->staff();
        $this->enrol($admin, Totp::randomSecret());

        $this->actingAs($admin)
            ->get(self::WEB_ROUTE)
            ->assertOk();

        $this->actingAs($admin)
            ->get('/faq/dz')
            ->assertOk();
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

    public function test_editor_draft_patch_mutation_route_is_gated_outside_panels(): void
    {
        // H7830: r7 снял RequireTwoFactor с группы web, но поверхность
        // изменения данных (перезапись data.json черновика лекции), живущая
        // вне стека панели /editor, остаётся под гейтом — как подключённая
        // 2FA без пройденного челленджа, так и вовсе без второго фактора.
        $draft = LectureDraft::create(['title' => 'H7830 draft']);

        $enrolledAdmin = $this->staff();
        $this->enrol($enrolledAdmin, Totp::randomSecret());
        $this->actingAs($enrolledAdmin)
            ->post("/editor/lectures/{$draft->id}/patch", ['patches' => []])
            ->assertRedirect(route('two-factor.show'));

        $plainAdmin = $this->staff();
        $this->actingAs($plainAdmin)
            ->post("/editor/lectures/{$draft->id}/patch", ['patches' => []])
            ->assertRedirect(route('two-factor.show'));

        // Чтение того же контура остаётся открытым по r7: гейт не запирает GET —
        // запрос доходит до контроллера и отдаёт 404 (HTML черновика не собран),
        // а не редирект на two-factor.show.
        $this->actingAs($enrolledAdmin)
            ->get("/editor/lectures/{$draft->id}/preview")
            ->assertNotFound();
    }

    public function test_editor_draft_patch_passes_after_the_panel_proof(): void
    {
        $draft = LectureDraft::create(['title' => 'H7830 proof draft']);

        $admin = $this->staff();
        $secret = Totp::randomSecret();
        $this->enrol($admin, $secret);
        $this->actingAs($admin)->get(route('two-factor.show'));

        $this->post(route('two-factor.verify'), ['code' => Totp::code($secret)]);

        // Гейт пройден: запрос доходит до контроллера и падает на валидации
        // формы (пустые patches), а не на редиректе two-factor.show.
        $this->actingAs($admin)
            ->post("/editor/lectures/{$draft->id}/patch", [])
            ->assertSessionHasErrors('patches');
    }

    public function test_livewire_updates_persist_the_panel_gate_for_panel_components(): void
    {
        // H7830 residual: вендорный POST /livewire/update несёт только группу
        // web, поэтому RequireTwoFactor зарегистрирован persistent-middleware —
        // панельные компоненты (чьё исходное рендер-маршрут несёт гейт)
        // перепроверяются на каждом обновлении. Публичные компоненты
        // (рендер без гейта) не затрагиваются.
        $this->assertContains(RequireTwoFactor::class, Livewire::getPersistentMiddleware());

        // Панельная страница по-прежнему гейтится (регресс r5→r7).
        $this->actingAs($this->staff())
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
