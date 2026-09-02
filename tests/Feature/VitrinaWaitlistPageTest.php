<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3834 + follow-up (01-09-2026): витрина /online/zhdun — сезонные секции
 * (ОСЕНЬ 2026 / НАЧАЛО 2027 / …, строки без даты — «дата уточняется»),
 * голоса скрыты кроме «осталось доголосовать ≤ 4», ссылка на преподавателя —
 * фильтр каталога, курс — ссылка на карточку; голосование — POST
 * /online/zhdun/vote в web-группе (сессия работает, гость → 401).
 */
class VitrinaWaitlistPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_voted_user_sees_retract_button_and_can_unvote(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'zhdun-voted',
            'course_title' => 'Вишнусахасранама',
            'teacher_name' => 'Гасунс Марцис Юрьевич',
            'min_payers' => 10,
            'kind' => 'other',
            'earliest_start_at' => '2027-10-01',
        ]);
        $user = User::factory()->create();
        $item->votes()->create(['user_id' => $user->id]);

        $resp = $this->actingAs($user)->get(route('shop.waitlist'));
        $resp->assertOk();
        // Кнопка-отзыв: «Голос учтён» теперь кликабельна.
        $resp->assertSee('data-waitlist-unvote="zhdun-voted"', false);
        $resp->assertSee('Отозвать голос');
        $resp->assertDontSee('data-waitlist-vote="zhdun-voted"');
        // 1 голос из 10 — счётчик скрыт.
        $resp->assertDontSee('Осталось доголосовать');

        // Отзыв голоса: голос удалён, идемпотентно.
        $this->actingAs($user)
            ->post(route('shop.waitlist.unvote'), ['slug' => 'zhdun-voted'])
            ->assertOk()
            ->assertJson(['ok' => true, 'votes' => 0]);
        $this->assertDatabaseMissing('waitlist_votes', [
            'course_waitlist_item_id' => $item->getKey(),
            'user_id' => $user->id,
        ]);
        $this->actingAs($user)
            ->post(route('shop.waitlist.unvote'), ['slug' => 'zhdun-voted'])
            ->assertJson(['ok' => true, 'votes' => 0]);

        // После отзыва кнопка «Намерен участвовать» вернулась.
        $this->actingAs($user)->get(route('shop.waitlist'))
            ->assertOk()
            ->assertSee('data-waitlist-vote="zhdun-voted"', false)
            ->assertDontSee('data-waitlist-unvote="zhdun-voted"');
    }

    public function test_guest_unvote_is_401(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-unvote-guest',
            'course_title' => 'Курс для гостя',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
        ]);

        $this->post(route('shop.waitlist.unvote'), ['slug' => 'zhdun-unvote-guest'])
            ->assertStatus(401);
    }

    public function test_course_title_always_clickable_search_fallback(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-unbound-title',
            'course_title' => 'Мегхадута Калидасы',
            'teacher_name' => 'Костина Екатерина Александровна',
            'min_payers' => 8,
            'kind' => 'other',
            'earliest_start_at' => '2027-10-15',
        ]);

        // Без привязанного курса заголовок ведёт в поиск каталога.
        $this->get(route('shop.waitlist'))
            ->assertOk()
            ->assertSee('/online/poisk/Мегхадута-Калидасы', false)
            ->assertSee('Мегхадута Калидасы</a>', false);
    }

    public function test_flag_off_aborts_404(): void
    {
        config(['features.waitlist_voting' => false]);

        $this->get(route('shop.waitlist'))->assertNotFound();
    }

    public function test_flag_on_renders_season_sections_and_vote_state(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'zhdun-bueler',
            'course_title' => 'Руководство по Бюлеру',
            'teacher_name' => 'Гасунс Марцис Юрьевич',
            'slot' => 'пн 18:00',
            'min_payers' => 10,
            'block_price_rub' => 8000,
            'kind' => 'grammar',
            'earliest_start_at' => '2027-10-01',
        ]);
        $other = User::factory()->create();
        $item->votes()->create(['user_id' => $other->id]);

        $resp = $this->get(route('shop.waitlist'));
        $resp->assertOk();
        $resp->assertSee('Список ожидания');
        $resp->assertSee('Руководство по Бюлеру');
        $resp->assertSee('ОСЕНЬ 2027');
        $resp->assertSee('Гасунс Марцис Юрьевич');
        // Ссылка на преподавателя — фильтр каталога.
        $resp->assertSee('/online/prepodavatel/Гасунс-Марцис-Юрьевич', false);
        $resp->assertSee('пн 18:00');
        $resp->assertSee('не раньше 01.10.2027');
        $resp->assertSee('8 000 ₽');
        $resp->assertSee('Намерен участвовать');

        // Голоса скрыты: до кворума далеко (9 осталось), счётчика нет.
        $resp->assertDontSee('Голосов:');
        $resp->assertDontSee('Осталось доголосовать');
    }

    public function test_remaining_shown_only_when_four_or_fewer_left(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'zhdun-nale',
            'course_title' => 'Сказание о Нале',
            'teacher_name' => 'Гасунс Марцис Юрьевич',
            'min_payers' => 8,
            'kind' => 'other',
            'earliest_start_at' => '2027-10-01',
        ]);
        // 5 голосов: осталось 3 (≤ 4) — счётчик виден.
        foreach (range(1, 5) as $i) {
            $item->votes()->create(['user_id' => User::factory()->create()->id]);
        }

        $this->get(route('shop.waitlist'))
            ->assertOk()
            ->assertSee('Осталось доголосовать: <span data-waitlist-count>3</span>', false);

        // 8 голосов: кворум — галочка, без чисел.
        foreach (range(6, 8) as $i) {
            $item->votes()->create(['user_id' => User::factory()->create()->id]);
        }

        $this->get(route('shop.waitlist'))
            ->assertOk()
            ->assertSee('Кворум набран')
            ->assertDontSee('Осталось доголосовать');
    }

    public function test_voted_user_sees_confirmation_without_button(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'zhdun-voted',
            'course_title' => 'Вишнусахасранама',
            'teacher_name' => 'Гасунс Марцис Юрьевич',
            'min_payers' => 10,
            'kind' => 'other',
            'earliest_start_at' => '2027-10-01',
        ]);
        $user = User::factory()->create();
        $item->votes()->create(['user_id' => $user->id]);

        $resp = $this->actingAs($user)->get(route('shop.waitlist'));
        $resp->assertOk();
        $resp->assertSee('Голос учтён');
        $resp->assertDontSee('data-waitlist-vote="zhdun-voted"');
        // 1 голос из 10 — счётчик скрыт.
        $resp->assertDontSee('Осталось доголосовать');
    }

    public function test_undated_rows_land_in_dated_tbd_section_last(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-undated',
            'course_title' => 'Йога и тантра',
            'teacher_name' => 'Пахомов Сергей Владимирович',
            'min_payers' => 8,
            'kind' => 'other',
        ]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-autumn26',
            'course_title' => 'Цифровая грамотность',
            'teacher_name' => 'Гасунс Марцис Юрьевич',
            'min_payers' => 10,
            'kind' => 'other',
            'earliest_start_at' => '2026-10-01',
        ]);

        $html = $this->get(route('shop.waitlist'))->assertOk()->getContent();
        $autumnPos = mb_strpos($html, 'ОСЕНЬ 2026');
        $tbdPos = mb_strpos($html, 'дата уточняется');

        $this->assertNotFalse($autumnPos);
        $this->assertNotFalse($tbdPos);
        $this->assertLessThan($tbdPos, $autumnPos);
        $this->assertStringContainsString('Йога и тантра', $html);
    }

    public function test_january_rows_sort_between_autumn_and_spring(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-spring27',
            'course_title' => 'Весенний курс',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
            'season' => '2027-spring',
        ]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-jan27',
            'course_title' => 'Январский курс',
            'teacher_name' => 'Гасунс Марцис Юрьевич',
            'min_payers' => 8,
            'kind' => 'other',
            'earliest_start_at' => '2027-01-10',
        ]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-autumn26b',
            'course_title' => 'Осенний курс 2026',
            'teacher_name' => 'Гасунс Марцис Юрьевич',
            'min_payers' => 8,
            'kind' => 'other',
            'earliest_start_at' => '2026-09-20',
        ]);

        $html = $this->get(route('shop.waitlist'))->assertOk()->getContent();
        $autumn26 = mb_strpos($html, 'ОСЕНЬ 2026');
        $jan27 = mb_strpos($html, 'НАЧАЛО 2027');
        $spring27 = mb_strpos($html, 'ВЕСНА 2027');

        $this->assertLessThan($jan27, $autumn26);
        $this->assertLessThan($spring27, $jan27);
    }

    public function test_authenticated_user_can_vote_via_web_route(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'zhdun-vote-web',
            'course_title' => 'Начальный санскрит',
            'teacher_name' => 'Трефилова Елена Вячеславовна',
            'min_payers' => 8,
            'kind' => 'grammar',
            'earliest_start_at' => '2026-10-01',
        ]);
        $user = User::factory()->create();

        // Web-маршрут: сессия + CSRF, как на витрине.
        $resp = $this->actingAs($user)
            ->post(route('shop.waitlist.vote'), ['slug' => 'zhdun-vote-web']);
        $resp->assertOk()->assertJson(['ok' => true, 'votes' => 1]);
        $this->assertDatabaseHas('waitlist_votes', [
            'course_waitlist_item_id' => $item->getKey(),
            'user_id' => $user->id,
        ]);

        // Повторный голос не дублирует.
        $this->actingAs($user)
            ->post(route('shop.waitlist.vote'), ['slug' => 'zhdun-vote-web'])
            ->assertJson(['ok' => true, 'votes' => 1]);
        $this->assertDatabaseCount('waitlist_votes', 1);
    }

    public function test_guest_vote_via_web_route_is_401(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-guest',
            'course_title' => 'Курс для гостя',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
        ]);

        $this->post(route('shop.waitlist.vote'), ['slug' => 'zhdun-guest'])
            ->assertStatus(401);

        // Страница рендерится гостю с кнопкой.
        $this->get(route('shop.waitlist'))->assertOk()->assertSee('Намерен участвовать');
    }

    public function test_legacy_api_vote_route_still_works_for_guest_401(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-api-guest',
            'course_title' => 'Курс для гостя API',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
        ]);

        $this->postJson('/api/public/waitlist/vote', ['slug' => 'zhdun-api-guest'])
            ->assertStatus(401);
    }

    public function test_scheduled_and_closed_hidden(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-sched',
            'course_title' => 'Уже запланировано',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
            'status' => 'scheduled',
        ]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-closed',
            'course_title' => 'Закрытый набор',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
            'status' => 'closed',
        ]);

        $this->get(route('shop.waitlist'))
            ->assertOk()
            ->assertDontSee('Уже запланировано')
            ->assertDontSee('Закрытый набор');
    }

    public function test_course_link_on_title_when_bound_and_payment_open_link(): void
    {
        config(['features.waitlist_voting' => true]);
        $course = Course::factory()->create(['is_visible' => true]);
        CourseWaitlistItem::create([
            'slug' => 'zhdun-open',
            'course_title' => 'Открытый набор',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
            'status' => 'payment_open',
            'course_id' => $course->id,
        ]);

        $this->get(route('shop.waitlist'))
            ->assertOk()
            ->assertSee('Открыта оплата — к курсу')
            ->assertSee(route('shop.course.show', $course->slug), false);
    }

    public function test_chip_on_catalog_hidden_when_flag_off(): void
    {
        config(['features.waitlist_voting' => false]);

        $this->get(route('shop.index'))->assertOk()->assertDontSee('/online/zhdun');
    }

    public function test_chip_on_catalog_visible_when_flag_on(): void
    {
        config(['features.waitlist_voting' => true]);

        $this->get(route('shop.index'))->assertOk()->assertSee(route('shop.waitlist'), false);
    }
}
