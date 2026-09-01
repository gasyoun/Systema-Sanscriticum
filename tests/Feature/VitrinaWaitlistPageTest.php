<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3834: рубрика «Список ожидания» на витрине /online/zhdun — флаг ON рендерит
 * карточки из course_waitlist_items с кнопкой голосования (гость → /login);
 * флаг OFF — 404; чип-вход на /online только при флаге ON.
 */
class VitrinaWaitlistPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_aborts_404(): void
    {
        config(['features.waitlist_voting' => false]);

        $this->get(route('shop.waitlist'))->assertNotFound();
    }

    public function test_flag_on_renders_cards_and_vote_state(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'zhdun-bueler',
            'course_title' => 'Руководство по Бюлеру',
            'teacher_name' => 'Марцис Гасунс',
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
        $resp->assertSee('Марцис Гасунс');
        $resp->assertSee('пн 18:00');
        $resp->assertSee('не раньше 01.10.2027');
        $resp->assertSee('8 000 ₽');
        $resp->assertSee('Голосовать');
        $resp->assertSee('Голосов: <span data-waitlist-count>1</span> из 10', false);

        // Проголосовавший видит «Голос учтён» и счётчик +1, без кнопки.
        $user = User::factory()->create();
        $item->votes()->create(['user_id' => $user->id]);
        $resp2 = $this->actingAs($user)->get(route('shop.waitlist'));
        $resp2->assertOk();
        $resp2->assertSee('Голос учтён');
        $resp2->assertDontSee('data-waitlist-vote="zhdun-bueler"');
        $resp2->assertSee('Голосов: <span data-waitlist-count>2</span> из 10', false);
    }

    public function test_guest_button_leads_to_login_on_vote(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'zhdun-guest',
            'course_title' => 'Курс для гостя',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
        ]);

        $resp = $this->postJson('/api/public/waitlist/vote', ['slug' => 'zhdun-guest']);
        $resp->assertStatus(401);

        // Страница рендерится гостю с кнопкой.
        $this->get(route('shop.waitlist'))->assertOk()->assertSee('Голосовать');
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

    public function test_payment_open_shows_course_link_when_bound(): void
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
