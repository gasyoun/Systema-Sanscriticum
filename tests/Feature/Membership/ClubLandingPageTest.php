<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\User;
use App\Support\Roles;

/**
 * Лендинг подписки на личный кабинет (голосовой запуск MG 23-11-2026).
 *
 * Контракт: страница — ПРЕВЬЮ ДЛЯ СУПЕР-АДМИНА до публичного запуска
 * после 1 января 2027. Продаёт месячную лестницу Свободный/Базовый/Клуб/Top
 * с ИИ-функциями (ответы на основе оцифрованных грамматик школы).
 * Цены — из строк БД с fallback на config; чекаут — только у активных
 * тарифов; годовая линейка («в записи», клубные 3/12 мес) с витрины снята,
 * и её цифр на странице нет вовсе.
 */
final class ClubLandingPageTest extends MembershipTestCase
{
    private function superAdmin(): User
    {
        return User::factory()->create(['role' => Roles::SUPER_ADMIN]);
    }

    public function test_only_super_admin_sees_the_preview(): void
    {
        $this->clubTariff(1);

        $this->get('/klub')->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]))
            ->get('/klub')->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get('/klub')->assertNotFound();

        $this->actingAs($this->superAdmin())
            ->get('/klub')->assertOk();
    }

    public function test_404_while_club_membership_flag_is_off(): void
    {
        config()->set('features.club_membership', false);

        $this->actingAs($this->superAdmin())->get('/klub')->assertNotFound();
    }

    public function test_preview_renders_even_without_tariff_rows(): void
    {
        // Снятый с продажи Базовый больше НЕ роняет страницу 404 — это и была
        // причина мёртвого /klub на проде. Без строк БД цены берутся из конфига.
        $this->actingAs($this->superAdmin())->get('/klub')
            ->assertOk()
            ->assertSee('1 000')
            ->assertSee('2 000')
            ->assertSee('5 000');
    }

    public function test_prices_come_from_db_and_only_active_tariffs_get_checkout(): void
    {
        $basic = $this->clubTariff(1, MembershipTier::Basic);
        $club = $this->clubTariff(1, MembershipTier::Club);

        $response = $this->actingAs($this->superAdmin())->get('/klub')->assertOk();

        // Цены месячных — из строк БД; Top без строки — из конфига.
        $response->assertSee('1 000');
        $response->assertSee('2 000');
        $response->assertSee('5 000');

        // Оба активных месячных ведут в свой чекаут; Top — без ссылки.
        $response->assertSee(route('checkout.show', $basic), false);
        $response->assertSee(route('checkout.show', $club), false);
        $this->assertSame(2, substr_count($response->getContent(), '/checkout/'));
    }

    public function test_inactive_tariff_is_shown_without_checkout(): void
    {
        $this->clubTariff(1, MembershipTier::Basic)->update(['is_active' => false]);
        $this->clubTariff(1, MembershipTier::Club);

        $response = $this->actingAs($this->superAdmin())->get('/klub')->assertOk();

        // Снятый с продажи Базовый виден (превью сетки должно быть полным),
        // но чекаут-ссылки у неактивного тарифа нет.
        $response->assertSee('Появится к запуску');
        $this->assertSame(1, substr_count($response->getContent(), '/checkout/'));
    }

    public function test_ai_features_are_visible_in_the_ladder(): void
    {
        $this->clubTariff(1);

        $this->actingAs($this->superAdmin())->get('/klub')->assertOk()
            ->assertSee('2 пробных ИИ-запроса')
            ->assertSee('Оцифрованные грамматики школы')
            ->assertSee('Грамотные ответы ИИ по всем пройденным курсам')
            ->assertSee('Запросы по стенограммам, презентациям и видео любого курса')
            // Ключевое различие Клуб/Top — на строке таблицы.
            ->assertSee('пройденным')
            ->assertSee('любым');
    }

    public function test_preview_banner_names_launch_window(): void
    {
        $this->clubTariff(1);

        $this->actingAs($this->superAdmin())->get('/klub')->assertOk()
            ->assertSee('Превью для супер-админа')
            ->assertSee('1 января 2027');
    }

    public function test_payment_reminders_separate_free_from_basic(): void
    {
        $this->clubTariff(1);

        $this->actingAs($this->superAdmin())->get('/klub')->assertOk()
            // Граница Free/Basic из голосового: «когда платить» — платная функция.
            ->assertSee('Напоминания, когда платить')
            ->assertSee('Напоминания: когда и сколько платить');
    }

    public function test_archive_line_is_parked_without_prices(): void
    {
        // Годовые тарифы сняты с витрины: ни карточек, ни цифр в копи.
        $this->clubTariff(3);
        $this->clubTariff(12);

        $this->actingAs($this->superAdmin())->get('/klub')->assertOk()
            ->assertDontSee('5 700')
            ->assertDontSee('20 400')
            ->assertDontSee('5 500')
            ->assertDontSee('35 000')
            // Честная пометка о снятии — без цифр.
            ->assertSee('Годовая линейка снята с витрины');
    }

    public function test_no_corpus_no_curator_promise_no_revocation(): void
    {
        $this->clubTariff(1);

        $response = $this->actingAs($this->superAdmin())->get('/klub')->assertOk();

        $response->assertDontSee('корпус');
        $response->assertDontSee('Корпус');
        $response->assertDontSee('поддержка куратора');
        $response->assertDontSee('с куратором');
        // Запрещённая формулировка «доступ будет отозван» — отсутствует.
        $response->assertDontSee('отозван');

        // Ручное продление названо прямо (формулировка согласована с H2644).
        $response->assertSee('не списывает');
    }
}
