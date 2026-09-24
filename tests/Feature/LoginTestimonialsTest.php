<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /login: бегущие колонки отзывов слева от формы (как на входе Glasp).
 * Блок выводится только при трёх и более видимых отзывах; иначе —
 * прежняя одиночная карточка. Скрытые отзывы на вход не попадают.
 */
class LoginTestimonialsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTestimonial(string $name, bool $visible = true, string $body = 'Отличный курс, спасибо!'): Testimonial
    {
        return Testimonial::create([
            'author_name' => $name,
            'body' => $body,
            'is_visible' => $visible,
        ]);
    }

    /** @test */
    public function login_shows_visible_testimonials_marquee(): void
    {
        $this->makeTestimonial('Анна Первая');
        $this->makeTestimonial('Борис Второй');
        $this->makeTestimonial('Вера Третья', body: str_repeat('Очень длинный отзыв. ', 30));
        $this->makeTestimonial('Скрытый Автор', visible: false);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-analytics="login-testimonials"', false)
            ->assertSee('Анна Первая')
            ->assertSee('Борис Второй')
            ->assertSee('Читать полностью')
            ->assertDontSee('Скрытый Автор')
            // форма входа на месте
            ->assertSee('id="login-form"', false);
    }

    /** @test */
    public function login_keeps_single_card_layout_with_too_few_testimonials(): void
    {
        $this->makeTestimonial('Анна Первая');
        $this->makeTestimonial('Борис Второй');

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-analytics="login-testimonials"', false)
            ->assertSee('id="login-form"', false);
    }

    /** @test */
    public function login_renders_without_any_testimonials(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-analytics="login-testimonials"', false)
            ->assertSee('id="login-form"', false);
    }
}
