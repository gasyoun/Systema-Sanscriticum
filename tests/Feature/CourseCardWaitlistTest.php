<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ждун в каталоге и на странице курса: курс, привязанный к строке
 * списка ожидания, вместо замка «Набор закрыт» показывает прогресс голосов
 * и кнопку «Намерен участвовать» (словарь и механика /online/zhdun).
 * Голосование с карточки — обычная веб-форма POST: успех → редирект назад
 * в «Голос учтен», гость → /login, его голос ждёт в сессии.
 */
class CourseCardWaitlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.waitlist_voting' => true]);
    }

    private function linkedItem(string $slug = 'card-zhdun', int $minPayers = 8, string $status = CourseWaitlistItem::STATUS_COLLECTING): CourseWaitlistItem
    {
        return CourseWaitlistItem::create([
            'slug' => $slug,
            'course_id' => Course::factory()->create()->id,
            'course_title' => 'Философия санкхьи',
            'teacher_name' => 'Леонов Максим Владимирович',
            'min_payers' => $minPayers,
            'kind' => 'other',
            'status' => $status,
            'earliest_start_at' => '2026-11-01',
        ]);
    }

    private function voteFor(CourseWaitlistItem $item, int $count): void
    {
        foreach (User::factory()->count($count)->create() as $voter) {
            $item->votes()->create(['user_id' => $voter->id]);
        }
    }

    // ================= карточка каталога =================

    public function test_catalog_card_of_collecting_course_shows_votes_and_vote_form(): void
    {
        $item = $this->linkedItem();
        $this->voteFor($item, 3);

        $this->get(route('shop.index'))
            ->assertOk()
            // Замок ушёл: статус ждуна, прогресс, дата и кнопка голоса.
            ->assertSee('Сбор голосов')
            ->assertSee('3 из 8')
            ->assertSee('Старт не раньше 01.11.2026')
            ->assertSee('data-testid="waitlist-vote-form"', false)
            ->assertSee('Намерен участвовать')
            ->assertSee('Когда удобно?')
            ->assertDontSee('Набор закрыт');
    }

    public function test_card_shows_remaining_votes_when_close_to_threshold(): void
    {
        $item = $this->linkedItem();
        $this->voteFor($item, 6);

        $this->get(route('shop.index'))
            ->assertOk()
            // Как на ждуне: до кворума ≤ 4 — показываем остаток, не общий счёт.
            ->assertSee('Осталось: 2')
            ->assertDontSee('из 8');
    }

    public function test_card_shows_quorum_met(): void
    {
        $item = $this->linkedItem(minPayers: 2);
        $this->voteFor($item, 2);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('Кворум набран');
    }

    public function test_card_without_waitlist_keeps_closed_block(): void
    {
        Course::factory()->create();

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('Набор закрыт')
            ->assertDontSee('Намерен участвовать');
    }

    public function test_flag_off_keeps_closed_block_even_when_linked(): void
    {
        config(['features.waitlist_voting' => false]);
        $this->linkedItem();

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('Набор закрыт')
            ->assertDontSee('Намерен участвовать');
    }

    public function test_payment_open_card_links_to_course_tariffs(): void
    {
        $this->linkedItem(status: CourseWaitlistItem::STATUS_PAYMENT_OPEN);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('Открыта оплата — к курсу')
            ->assertDontSee('Намерен участвовать');
    }

    public function test_voted_user_sees_vote_counted_on_card(): void
    {
        $item = $this->linkedItem();
        $user = User::factory()->create();
        $item->votes()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get(route('shop.index'))
            ->assertOk()
            ->assertSee('Голос учтен')
            ->assertSee('data-testid="waitlist-unvote"', false)
            ->assertDontSee('data-testid="waitlist-vote-form"', false);
    }

    // ================= голосование формой (web-режим) =================

    public function test_web_vote_from_card_counts_vote_and_redirects_back(): void
    {
        $item = $this->linkedItem();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('shop.index'))
            ->post(route('shop.waitlist.vote'), ['slug' => 'card-zhdun', 'slot_preference' => 'evening'])
            ->assertRedirect(route('shop.index'));

        $this->assertDatabaseHas('waitlist_votes', [
            'course_waitlist_item_id' => $item->getKey(),
            'user_id' => $user->id,
            'slot_preference' => 'evening',
        ]);

        // После редиректа карточка в состоянии «Голос учтен», сверху — тост «Спасибо».
        $this->actingAs($user)->get(route('shop.index'))
            ->assertOk()
            ->assertSee('Голос учтен')
            ->assertSee('data-waitlist-voted-toast', false);
    }

    public function test_guest_web_vote_redirects_to_login_and_pends(): void
    {
        $this->linkedItem();

        $this->from(route('shop.index'))
            ->post(route('shop.waitlist.vote'), ['slug' => 'card-zhdun'])
            ->assertRedirect(route('login'));

        $this->assertSame('card-zhdun', session('waitlist.pending_vote')['slug']);
        // После входа CastPendingWaitlistVote вернёт гостя на витрину ждуна.
        $this->assertSame(route('shop.waitlist'), session('url.intended'));
    }

    public function test_web_unvote_from_card_removes_vote(): void
    {
        $item = $this->linkedItem();
        $user = User::factory()->create();
        $item->votes()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->from(route('shop.index'))
            ->post(route('shop.waitlist.unvote'), ['slug' => 'card-zhdun'])
            ->assertRedirect(route('shop.index'));

        $this->assertDatabaseMissing('waitlist_votes', [
            'course_waitlist_item_id' => $item->getKey(),
            'user_id' => $user->id,
        ]);
    }

    // ================= страница курса =================

    public function test_course_page_shows_waitlist_box_instead_of_lock(): void
    {
        $item = $this->linkedItem();
        $this->voteFor($item, 3);

        $this->get(route('shop.course.show', $item->course->slug))
            ->assertOk()
            ->assertSee('data-testid="course-waitlist-box"', false)
            ->assertSee('Сбор голосов')
            ->assertSee('3 из 8')
            ->assertSee('Намерен участвовать')
            ->assertSee('Список ожидания: что это и какие курсы ещё собираются')
            ->assertDontSee('В данный момент запись на этот курс не ведется');
    }

    public function test_course_page_lock_survives_without_waitlist(): void
    {
        $course = Course::factory()->create();

        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertSee('В данный момент запись на этот курс не ведется')
            ->assertDontSee('data-testid="course-waitlist-box"', false);
    }
}
