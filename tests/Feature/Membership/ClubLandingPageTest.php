<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\User;

/**
 * Лендинг + прайсинг клуба (H2645): страница живёт только при включённом
 * контуре H2644, цены приходят из БД, исключения видимы, формулировка
 * «что заканчивается» дословно совпадает с карточкой кабинета, и на странице
 * нет ни третьего тарифа, ни корпуса, ни обещаний куратора.
 */
final class ClubLandingPageTest extends MembershipTestCase
{
    /** Три клубных тарифа с прод-ценами (месяц/квартал/год). */
    private function makeTariffs(): array
    {
        $monthly = $this->clubTariff(1);
        $quarterly = $this->clubTariff(3);
        $quarterly->update(['price' => 4000, 'description' => 'Три месяца сразу, выгоднее помесячной оплаты на 11 %.']);
        $yearly = $this->clubTariff(12);
        $yearly->update(['price' => 15000, 'description' => 'Год сразу, выгоднее помесячной оплаты на 17 %.']);

        return [$monthly, $quarterly->fresh(), $yearly->fresh()];
    }

    public function test_404_while_club_membership_flag_is_off(): void
    {
        $this->makeTariffs();
        config()->set('features.club_membership', false);

        $this->get('/klub')->assertNotFound();
    }

    public function test_404_without_active_club_tariffs(): void
    {
        // Курс клуба есть (setUp), тарифов нет — продавать нечего.
        $this->get('/klub')->assertNotFound();

        // Неактивный тариф тоже не продаётся.
        $this->clubTariff(1)->update(['is_active' => false]);
        $this->get('/klub')->assertNotFound();
    }

    public function test_prices_come_from_db_and_ctas_lead_to_checkout(): void
    {
        [$monthly, $quarterly, $yearly] = $this->makeTariffs();

        $response = $this->get('/klub')->assertOk();

        // Цены — из строк БД, в формате магазина.
        $response->assertSee('1 500');
        $response->assertSee('4 000');
        $response->assertSee('15 000');

        // CTA каждого тарифа ведёт в реальный чекаут этого тарифа.
        foreach ([$monthly, $quarterly, $yearly] as $tariff) {
            $response->assertSee(route('checkout.show', $tariff), false);
        }
    }

    public function test_exclusions_are_visible_for_both_levels(): void
    {
        $this->makeTariffs();

        $response = $this->get('/klub')->assertOk();

        // Уровни.
        $response->assertSee('Свободный');
        $response->assertSee('Клуб');

        // Исключения — на странице, не спрятаны.
        $response->assertSee('Живые потоки');
        $response->assertSee('Проверка домашних заданий');
        $response->assertSee('Сертификат');
        $response->assertSee('Платные курсы, которые вы не покупали');
    }

    public function test_retained_access_statement_matches_cabinet_verbatim(): void
    {
        $this->makeTariffs();

        $response = $this->get('/klub')->assertOk();

        // Дословное ядро карточки кабинета (H2644): заканчивается ПРАВО,
        // открытые уроки не отбираются. Правки — только синхронно с
        // student/partials/club-membership.blade.php.
        $response->assertSee('право доступа');
        $response->assertSee('мы у вас не отбираем');
        $response->assertSee('не списывает');

        // Запрещённая формулировка «доступ будет отозван» — отсутствует.
        $response->assertDontSee('отозван');
    }

    public function test_no_third_tier_no_corpus_no_curator_promise(): void
    {
        $this->makeTariffs();

        $response = $this->get('/klub')->assertOk();

        // Третий тариф не назван и не оценён.
        $response->assertDontSee('5 000');
        $response->assertDontSee('готовится');

        // Корпус не упоминается ни в каком регистре.
        $response->assertDontSee('корпус');
        $response->assertDontSee('Корпус');

        // Куратор упоминается только как исключение («и время куратора» /
        // «куратор и сертификат — это курсы»), никогда как обещание клуба.
        $response->assertDontSee('поддержка куратора');
        $response->assertDontSee('с куратором');
    }

    public function test_free_tier_grant_line_is_gated_by_its_flag(): void
    {
        $this->makeTariffs();

        // setUp включает membership_free_tier — обещание в настоящем времени.
        $this->get('/klub')->assertOk()
            ->assertSee('который вы уже оплачивали')
            ->assertDontSee('Скоро');

        // Флаг ВЫКЛ — то же обещание помечено как будущее, не как действующее.
        config()->set('features.membership_free_tier', false);
        $this->get('/klub')->assertOk()->assertSee('Скоро');
    }

    public function test_logged_in_student_is_routed_to_cabinet(): void
    {
        $this->makeTariffs();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/klub')->assertOk()
            ->assertSee('Открыть кабинет')
            ->assertSee(route('student.dashboard'), false);
    }

    public function test_shop_index_links_to_club_only_when_flag_is_on(): void
    {
        $this->makeTariffs();

        $this->get('/online')->assertOk()
            ->assertSee(route('membership.landing'), false);

        config()->set('features.club_membership', false);
        $this->get('/online')->assertOk()
            ->assertDontSee(route('membership.landing'), false);
    }
}
