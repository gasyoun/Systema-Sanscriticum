<?php

namespace Tests\Feature\Shop;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
use App\Models\Tariff;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H323 — beginner on-ramp + social proof на витрине: страница «С чего начать»,
 * уровень курса (бейдж + фильтр), общесайтовая полоса доверия/отзывов,
 * смягчённое предупреждение для гостей на чекауте.
 */
class ShopOnrampTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function start_page_renders_quiz_ladder_and_levels(): void
    {
        $this->get('/online/s-chego-nachat')
            ->assertOk()
            ->assertSee('С чего начать изучение санскрита')
            ->assertSee('data-analytics="onramp-quiz"', false)
            ->assertSee('data-analytics="product-ladder"', false)
            ->assertSee('data-analytics="levels-explainer"', false)
            ->assertSee('data-analytics="trust-strip"', false);
    }

    /** @test */
    public function start_page_ladder_shows_honest_min_prices(): void
    {
        $live = Course::factory()->live()->create();
        Tariff::factory()->for($live)->create(['type' => 'block', 'price' => 4500, 'is_active' => true]);

        $recorded = Course::factory()->create(['format' => 'recorded']);
        Tariff::factory()->for($recorded)->create(['type' => 'full', 'price' => 1900, 'is_active' => true]);

        $this->get('/online/s-chego-nachat')
            ->assertOk()
            ->assertSee('от 4 500 ₽/блок')
            ->assertSee('от 1 900 ₽');
    }

    /** @test */
    public function catalog_level_filter_shows_only_matching_courses(): void
    {
        $beginner = Course::factory()->create(['title' => 'Course For Beginners Zzz', 'level' => 'beginner']);
        $advanced = Course::factory()->create(['title' => 'Course For Advanced Yyy', 'level' => 'advanced']);

        Livewire::test(CourseCatalog::class)
            ->set('level', 'beginner')
            ->assertSee($beginner->title)
            ->assertDontSee($advanced->title);
    }

    /** @test */
    public function unknown_level_value_does_not_filter_anything(): void
    {
        Course::factory()->create(['title' => 'Course Alpha Qqq', 'level' => 'beginner']);
        Course::factory()->create(['title' => 'Course Beta Www']);

        Livewire::test(CourseCatalog::class)
            ->set('level', 'bogus')
            ->assertSee('Course Alpha Qqq')
            ->assertSee('Course Beta Www');
    }

    /** @test */
    public function level_chips_appear_only_when_courses_are_classified(): void
    {
        Course::factory()->create(['title' => 'Unclassified Course Xxx']);

        Livewire::test(CourseCatalog::class)
            ->assertDontSee('data-analytics="level-filter"', false);

        Course::factory()->create(['title' => 'Beginner Course Vvv', 'level' => 'beginner']);

        Livewire::test(CourseCatalog::class)
            ->assertSee('data-analytics="level-filter"', false)
            ->assertSee('С нуля');
    }

    /** @test */
    public function course_card_shows_level_badge(): void
    {
        Course::factory()->create(['title' => 'Badged Course Uuu', 'level' => 'continuing']);

        Livewire::test(CourseCatalog::class)
            ->assertSee('Продолжающим');
    }

    /** @test */
    public function reset_filters_clears_level(): void
    {
        Course::factory()->create(['title' => 'Beginner Only Ttt', 'level' => 'beginner']);
        Course::factory()->create(['title' => 'Plain Course Sss']);

        Livewire::test(CourseCatalog::class)
            ->set('level', 'beginner')
            ->assertDontSee('Plain Course Sss')
            ->call('resetFilters')
            ->assertSee('Plain Course Sss');
    }

    /** @test */
    public function catalog_page_shows_trust_band_and_start_link(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('data-analytics="trust-strip"', false)
            ->assertSee('С '.config('trust.since_year').' года')
            ->assertSee('/online/s-chego-nachat');
    }

    /** @test */
    public function graduates_tile_hidden_without_configured_count(): void
    {
        config(['trust.graduates_count' => null]);

        $this->get('/online')->assertOk()->assertDontSee('учеников');
    }

    /** @test */
    public function graduates_tile_shown_when_configured(): void
    {
        config(['trust.graduates_count' => 5000]);

        $this->get('/online')->assertOk()->assertSee('5 000+ учеников');
    }

    /** @test */
    public function catalog_shows_only_featured_visible_testimonials(): void
    {
        Testimonial::create([
            'author_name' => 'Featured Reviewer Rrr',
            'body' => 'Отличный курс, рекомендую.',
            'is_visible' => true,
            'is_featured' => true,
        ]);
        Testimonial::create([
            'author_name' => 'Hidden Reviewer Qqq',
            'body' => 'Не для витрины.',
            'is_visible' => true,
            'is_featured' => false,
        ]);
        Testimonial::create([
            'author_name' => 'Invisible Reviewer Ppp',
            'body' => 'Скрытый отзыв.',
            'is_visible' => false,
            'is_featured' => true,
        ]);

        $this->get('/online')
            ->assertOk()
            ->assertSee('Featured Reviewer Rrr')
            ->assertDontSee('Hidden Reviewer Qqq')
            ->assertDontSee('Invisible Reviewer Ppp');
    }

    /** @test */
    public function guest_purchase_warning_is_softened_on_course_page(): void
    {
        $course = Course::factory()->create(['slug' => 'onramp-warning-course']);
        Tariff::factory()->for($course)->create(['title' => 'Весь курс целиком']);

        $this->get('/k/onramp-warning-course')
            ->assertOk()
            // Красный блок-блокиратор новых покупателей убран.
            ->assertDontSee('не регистрируйтесь самостоятельно')
            ->assertDontSee('Он создаст аккаунт вручную')
            // Спокойная строка для возвратных студентов осталась.
            ->assertSee('Уже покупали наши курсы раньше?');
    }

    /** @test */
    public function authenticated_user_does_not_see_guest_note(): void
    {
        $course = Course::factory()->create(['slug' => 'onramp-auth-course']);
        Tariff::factory()->for($course)->create(['title' => 'Весь курс целиком']);

        $user = User::factory()->create();

        // «ничего не потеряется» — фраза, уникальная для guest-блока
        // (модалка логина рендерится скрытой для всех — её текст не индикатор).
        $this->actingAs($user)
            ->get('/k/onramp-auth-course')
            ->assertOk()
            ->assertDontSee('ничего не потеряется');
    }
}
