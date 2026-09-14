<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4463: welcome-тур кабинета студента.
 *
 * Флаг ON — на главной кабинета есть разметка тура и кнопка «Обзор кабинета»
 * (и в классике, и в гибриде — один роут student.dashboard отдаёт обе вьюхи);
 * флаг OFF или режим «войти как» (Impersonation, H1947) — кабинет байт-стабилен.
 * Показ/скип живут в localStorage — это клиентская логика Alpine, сервер её
 * не видит и не тестирует.
 */
class CabinetTourTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_on_renders_tour_and_replay_button(): void
    {
        config(['features.cabinet_tour' => true]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertSee('data-testid="cabinet-tour"', false);
        $resp->assertSee('Обзор кабинета');
        $resp->assertSee('cabinet_tour_v1', false);
        $resp->assertSee('Добро пожаловать в личный кабинет!', false);
        // Повторное открытие — событие Alpine из кнопки дашборда.
        $resp->assertSee('open-cabinet-tour', false);
        // Финальный CTA ведёт в существующий quiz «Проверить кабинет».
        $resp->assertSee(route('student.cabinet-mastery'), false);
    }

    public function test_flag_off_renders_no_tour(): void
    {
        config(['features.cabinet_tour' => false]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertDontSee('data-testid="cabinet-tour"', false);
        $resp->assertDontSee('Обзор кабинета');
    }

    public function test_impersonation_hides_tour(): void
    {
        // Режим «войти как»: превью кабинета не должно ловить попап и
        // записывать себе localStorage-ключ студента (H1947-принцип).
        config(['features.cabinet_tour' => true, 'features.staff_impersonation' => true]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)
            ->withSession([Impersonation::SESSION_IMPERSONATOR => 999])
            ->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertDontSee('data-testid="cabinet-tour"', false);
        $resp->assertDontSee('Обзор кабинета');
    }

    public function test_hybrid_home_renders_tour(): void
    {
        // Гибридный «Сегодня» рендерится тем же роутом при cabinet_hybrid ON.
        config(['features.cabinet_tour' => true, 'features.cabinet_hybrid' => true]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertSee('data-testid="cabinet-tour"', false);
        $resp->assertSee('Обзор кабинета');
    }

    public function test_srs_slide_appears_only_with_srs_enabled(): void
    {
        config(['features.cabinet_tour' => true, 'srs.enabled' => true]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertSee('Карточки для запоминания', false);

        config(['srs.enabled' => false]);
        $resp2 = $this->actingAs($user)->get(route('student.dashboard'));
        $resp2->assertDontSee('Карточки для запоминания', false);
    }
}
