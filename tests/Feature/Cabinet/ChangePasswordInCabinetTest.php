<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\User;
use App\Services\Access\StudentUnblockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Сменить пароль» есть в обоих кабинетах (29-09-2026): прод живёт на
 * CABINET_HYBRID, а кнопка и модалка оставались только на легаси-дашборде.
 * Студенту после входа по ссылке куратора («задайте пароль») было негде это сделать.
 */
class ChangePasswordInCabinetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool}> */
    public static function cabinets(): array
    {
        return ['hybrid' => [true], 'legacy' => [false]];
    }

    #[DataProvider('cabinets')]
    public function test_cabinet_home_offers_change_password(bool $hybrid): void
    {
        config(['features.cabinet_hybrid' => $hybrid]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Сменить пароль')
            ->assertSee('open-change-password', false)
            ->assertSee('data-change-password-modal', false)
            ->assertSee(route('student.password.update'), false)
            ->assertSee(route('password.request'), false);
    }

    #[DataProvider('cabinets')]
    public function test_password_change_succeeds_and_is_confirmed_on_the_cabinet(bool $hybrid): void
    {
        config(['features.cabinet_hybrid' => $hybrid]);
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.password.update'), [
                'current_password' => 'old-password-1',
                'password' => 'new-password-2',
                'password_confirmation' => 'new-password-2',
            ])
            ->assertRedirect(route('student.dashboard'));

        $this->assertTrue(Hash::check('new-password-2', $user->fresh()->password));

        $this->actingAs($user->fresh())->get(route('student.dashboard'))
            ->assertSee('Пароль успешно изменён.');
    }

    public function test_hybrid_cabinet_shows_the_set_password_hint_after_an_admin_login_link(): void
    {
        config(['features.cabinet_hybrid' => true]);
        $student = User::factory()->create();
        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null);
        $parts = explode('/', $result['login_link']);

        $this->followingRedirects()
            ->get('/login-link/'.end($parts))
            ->assertOk()
            ->assertSee('Задайте новый пароль')
            ->assertSee('Сменить пароль');
    }
}
