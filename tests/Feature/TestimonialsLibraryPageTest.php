<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /otzyvy: все видимые отзывы; колонки с анимацией собирает скрипт в браузере,
 * а сервер отдаёт сетку-источник — её видят поисковики, читалки и браузер без JS.
 * Две темы (тёмная — как на входе, светлая) с переключателем.
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

    public function test_page_renders_source_grid_with_all_visible_testimonials_and_theme_switch(): void
    {
        $this->make('Анна Первая', ['reviewed_at' => '2026-09-13', 'city' => 'Казань']);
        $this->make('Борис Второй');
        $this->make('Скрытый Автор', ['is_visible' => false]);

        $this->get('/otzyvy')
            ->assertOk()
            ->assertSee('data-otz-source', false)
            ->assertSee('Анна Первая')
            ->assertSee('Казань · 13 сентября 2026')
            ->assertSee('Борис Второй')
            ->assertDontSee('Скрытый Автор')
            ->assertSee('data-theme-set="dark"', false)
            ->assertSee('data-theme-set="light"', false);
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

    public function test_long_review_is_expandable_card(): void
    {
        $this->make('Длинный Автор', ['body' => str_repeat('Очень длинный отзыв. ', 30)]);

        $this->get('/otzyvy')
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('Читать полностью');
    }
}
