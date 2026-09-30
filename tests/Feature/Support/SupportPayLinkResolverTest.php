<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Support\SupportPayLinkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * features.support_block_pay_link (30-09-2026): ответы поддержки ведут должника
 * на оплату конкретного блока, а не на /login с пустым «{course}».
 */
class SupportPayLinkResolverTest extends TestCase
{
    use RefreshDatabase;

    private const D2 = "Намасте, {name}!\n\nОплатить курс «{course}» удобнее из личного кабинета:\n{pay_link}";

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.support_block_pay_link' => true, 'features.cabinet_hybrid' => true]);
    }

    /**
     * Курс блоков 1..$last (текущий — последний); оплачены 1..$paidUpTo →
     * долг = блоки ($paidUpTo+1)..$last, у каждого тариф block_N.
     */
    private function debtorCourse(User $user, string $title, int $last, int $paidUpTo): Course
    {
        $course = Course::factory()->create(['is_active' => true, 'title' => $title]);
        for ($n = 1; $n <= $last; $n++) {
            $factory = CourseBlock::factory()->for($course);
            ($n === $last ? $factory->current() : $factory)->create(['number' => $n]);
            Tariff::create(['course_id' => $course->id, 'title' => 'Блок '.$n, 'type' => 'block', 'block_number' => $n, 'price' => 8000, 'is_active' => true]);
        }
        for ($n = 1; $n <= $paidUpTo; $n++) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 8000, 'tariff' => 'block_'.$n, 'status' => 'paid',
                'start_block' => $n, 'end_block' => $n, 'is_conditional' => false,
            ]);
        }

        return $course;
    }

    private function checkoutUrl(Course $course, int $block): string
    {
        return route('checkout.show', Tariff::where('course_id', $course->id)->where('block_number', $block)->firstOrFail());
    }

    private function resolve(User $user, ?string $text): ?array
    {
        return app(SupportPayLinkResolver::class)->resolve($user, $text);
    }

    public function test_named_block_from_the_debt_links_to_its_checkout(): void
    {
        $user = User::factory()->create();
        $course = $this->debtorCourse($user, 'Лейтан', 5, 2);

        $link = $this->resolve($user, 'Я не понимаю, как оплатить 4 Лейтана');

        $this->assertSame($course->id, $link['course']->id);
        $this->assertSame(4, $link['block']);
        $this->assertSame($this->checkoutUrl($course, 4), $link['url']);
    }

    public function test_several_blocks_without_a_number_go_to_the_access_page(): void
    {
        $user = User::factory()->create();
        $course = $this->debtorCourse($user, 'Лейтан', 5, 2);

        $link = $this->resolve($user, 'как оплатить?');

        $this->assertSame($course->id, $link['course']->id);
        $this->assertNull($link['block']);
        $this->assertSame(route('student.access'), $link['url']);
    }

    public function test_number_outside_the_debt_is_treated_as_no_number(): void
    {
        $user = User::factory()->create();
        $this->debtorCourse($user, 'Лейтан', 5, 2);

        $this->assertSame(route('student.access'), $this->resolve($user, 'оплатить 99')['url']);
    }

    public function test_single_block_debt_links_to_that_block_even_without_a_number(): void
    {
        $user = User::factory()->create();
        $course = $this->debtorCourse($user, 'Лейтан', 3, 2);

        $link = $this->resolve($user, 'как оплатить?');

        $this->assertSame(3, $link['block']);
        $this->assertSame($this->checkoutUrl($course, 3), $link['url']);
    }

    public function test_two_courses_in_debt_pick_by_block_number_or_give_up(): void
    {
        $user = User::factory()->create();
        $this->debtorCourse($user, 'Первый', 5, 3);   // долг 4–5
        $second = $this->debtorCourse($user, 'Второй', 9, 7); // долг 8–9

        $link = $this->resolve($user, 'оплатить 8');
        $this->assertSame($second->id, $link['course']->id);
        $this->assertSame($this->checkoutUrl($second, 8), $link['url']);

        $this->assertNull($this->resolve($user, 'как оплатить?'));
    }

    public function test_no_debt_returns_null(): void
    {
        $this->assertNull($this->resolve(User::factory()->create(), 'оплатить 4'));
    }

    public function test_template_render_fills_course_and_block_link_when_flag_on(): void
    {
        $user = User::factory()->create();
        $course = $this->debtorCourse($user, 'Лейтан', 5, 2);
        $template = new MessageTemplate(['body' => self::D2]);

        $text = $template->renderForSupport($user, 'как оплатить 4');

        $this->assertStringContainsString('«Лейтан»', $text);
        $this->assertStringContainsString($this->checkoutUrl($course, 4), $text);
        $this->assertStringNotContainsString(url('/login'), $text);
    }

    public function test_template_render_is_unchanged_when_flag_off(): void
    {
        config(['features.support_block_pay_link' => false]);
        $user = User::factory()->create();
        $this->debtorCourse($user, 'Лейтан', 5, 2);
        $template = new MessageTemplate(['body' => self::D2]);

        $this->assertSame($template->render($user), $template->renderForSupport($user, 'как оплатить 4'));
        $this->assertStringContainsString('«»', $template->renderForSupport($user, 'как оплатить 4'));
    }
}
