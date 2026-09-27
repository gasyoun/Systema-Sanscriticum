<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /otzyvy: все видимые отзывы; колонки с анимацией собирает скрипт в браузере,
 * а сервер отдаёт сетку-источник — её видят поисковики, читалки и браузер без JS.
 * Оформление «как у Glasp»: градиент, белые карточки, текст целиком, без тем,
 * без звёзд и без раскрытия длинных отзывов.
 */
class TestimonialsLibraryPageTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $name, array $extra = []): Testimonial
    {
        return Testimonial::create(array_merge([
            'author_name' => $name,
            'body' => 'Отличный курс, спасибо!',
            'is_visible' => true,
        ], $extra));
    }

    public function test_page_renders_source_grid_with_all_visible_testimonials_in_glasp_style(): void
    {
        $this->make('Анна Первая', ['reviewed_at' => '2026-09-13', 'city' => 'Казань', 'rating' => 5]);
        $this->make('Борис Второй');
        $this->make('Скрытый Автор', ['is_visible' => false]);

        $this->get('/otzyvy')
            ->assertOk()
            ->assertSee('data-glx-source', false)
            ->assertSee('<h1 class="sr-only">Отзывы учеников</h1>', false)
            ->assertSee('Анна Первая')
            ->assertSee('Казань · 13 сентября 2026')
            ->assertSee('Борис Второй')
            ->assertDontSee('Скрытый Автор')
            // Как у Glasp: без переключателя тем и без звёзд оценки.
            ->assertDontSee('data-theme-set', false)
            ->assertDontSee('fa-star', false);
    }

    public function test_featured_first_then_newest_review_date(): void
    {
        $this->make('Старый Отзыв', ['reviewed_at' => '2025-01-10']);
        $this->make('Без Даты');
        $this->make('Свежий Отзыв', ['reviewed_at' => '2026-09-01']);
        $this->make('Избранный Отзыв', ['is_featured' => true, 'reviewed_at' => '2024-05-05']);

        $this->get('/otzyvy')->assertSeeInOrder([
            'Избранный Отзыв', 'Свежий Отзыв', 'Старый Отзыв', 'Без Даты',
        ]);
    }

    public function test_long_review_is_shown_in_full_without_expand_control(): void
    {
        $body = str_repeat('Очень длинный отзыв. ', 30).'Последняя фраза отзыва.';
        $this->make('Длинный Автор', ['body' => $body]);

        $this->get('/otzyvy')
            ->assertSee('Последняя фраза отзыва.')
            ->assertDontSee('Читать полностью')
            ->assertDontSee('aria-expanded', false);
    }
}
