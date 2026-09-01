<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3815: карточка «Список ожидания» в кабинете — флаг ON рендерит строки с
 * кнопкой голосования; флаг OFF кабинет байт-стабилен.
 */
class CabinetWaitlistCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_renders_no_card(): void
    {
        config(['features.waitlist_voting' => false]);
        CourseWaitlistItem::create([
            'slug' => 'hidden-card-1',
            'course_title' => 'Секретный курс',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
        ]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertDontSee('Список ожидания');
    }

    public function test_flag_on_renders_rows_and_my_vote_state(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = CourseWaitlistItem::create([
            'slug' => 'card-course-1',
            'course_title' => 'Руководство по Бюлеру',
            'teacher_name' => 'Марцис Гасунс',
            'min_payers' => 10,
            'block_price_rub' => 8000,
            'kind' => 'grammar',
            'earliest_start_at' => '2027-10-01',
        ]);
        $other = User::factory()->create();
        $item->votes()->create(['user_id' => $other->id]);

        $user = User::factory()->create();
        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertSee('Список ожидания');
        $resp->assertSee('Руководство по Бюлеру');
        $resp->assertSee('Голосовать');
        $resp->assertSee('Голосов:');

        // Проголосовавший видит «Голос учтён», без кнопки.
        $item->votes()->create(['user_id' => $user->id]);
        $resp2 = $this->actingAs($user)->get(route('student.dashboard'));
        $resp2->assertSee('Голос учтён');
        $resp2->assertDontSee('data-waitlist-vote="card-course-1"');
    }

    public function test_scheduled_and_closed_items_are_hidden_from_cabinet(): void
    {
        config(['features.waitlist_voting' => true]);
        CourseWaitlistItem::create([
            'slug' => 'gone-1',
            'course_title' => 'Уже идёт курс',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
            'status' => 'scheduled',
        ]);
        CourseWaitlistItem::create([
            'slug' => 'closed-1',
            'course_title' => 'Закрытый набор',
            'teacher_name' => 'Т',
            'min_payers' => 8,
            'kind' => 'other',
            'status' => 'closed',
        ]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertDontSee('Уже идёт курс');
        $resp->assertDontSee('Закрытый набор');
        // Карточка вообще не рендерится (пустая коллекция).
        $resp->assertDontSee('Список ожидания');
    }
}
