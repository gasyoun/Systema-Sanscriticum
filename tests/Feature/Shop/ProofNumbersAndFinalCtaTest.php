<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1085 (H1068 editorial audit §2/§3.6, items R3+R7): proof-числа единым
 * config/trust.php вместо разъезжающихся хардкодов, и финальный CTA больше не
 * пишет «изучать санскрит» на курсах по другим темам.
 */
class ProofNumbersAndFinalCtaTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_shows_books_pages_and_patron_topup_when_configured(): void
    {
        config([
            'trust.books_published' => 20,
            'trust.books_pages' => 3834,
            'trust.crowdfunding_raised_rub' => 1_270_000,
            'trust.crowdfunding_patron_topup_rub' => 700_000,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('3 834 стр.', false);
        $response->assertSee('от меценатов', false);
    }

    public function test_home_page_hides_patron_topup_and_pages_when_unset(): void
    {
        config([
            'trust.books_pages' => null,
            'trust.crowdfunding_patron_topup_rub' => null,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('стр.)', false);
        $response->assertDontSee('от меценатов', false);
    }

    public function test_final_cta_defaults_to_sanskrit_when_course_has_no_subject_override(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'cta_subject' => null,
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertSee('Готовы начать изучать санскрит?', false);
    }

    public function test_final_cta_uses_the_course_subject_override(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'cta_subject' => 'философию',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertSee('Готовы начать изучать философию?', false);
        $response->assertDontSee('Готовы начать изучать санскрит?', false);
    }
}
