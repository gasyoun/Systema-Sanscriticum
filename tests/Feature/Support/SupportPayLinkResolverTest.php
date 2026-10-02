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

    /**
     * Прод, 30-09-2026: «как оплатить 10 блок грамматики санскрита в 55й группе».
     * По гр.55 долга нет, зато «10» совпадало с договорённостью по другому курсу
     * (блоки 1–12) — уходила ссылка «Оплата и доступ» не про тот курс.
     */
    public function test_named_group_wins_over_a_block_number_matching_another_debt(): void
    {
        $user = User::factory()->create();
        $this->debtorCourse($user, 'Медленное чтение', 12, 0);        // долг 1–12, включая 10
        $gr55 = $this->debtorCourse($user, 'Грамматика по Кочергиной гр.55', 20, 20); // долга нет
        $this->debtorCourse($user, 'Грамматика по Кочергиной гр.58', 12, 12);

        $link = $this->resolve($user, 'добрый день, как оплатить 10 блок грамматики санскрита в 55й группе?');

        $this->assertSame($gr55->id, $link['course']->id);
        $this->assertSame(10, $link['block']);
        $this->assertSame($this->checkoutUrl($gr55, 10), $link['url']);
    }

    public function test_named_group_with_a_debt_follows_the_debt(): void
    {
        $user = User::factory()->create();
        $gr60 = $this->debtorCourse($user, 'Грамматика по Кочергиной гр.60', 5, 1); // долг 2–5

        $this->assertSame($this->checkoutUrl($gr60, 3), $this->resolve($user, 'оплатить 3 блок гр.60')['url']);
        $this->assertSame(route('student.access'), $this->resolve($user, 'как оплатить гр. 60?')['url']);
    }

    public function test_new_course_named_by_title_is_linked_without_any_debt(): void
    {
        $user = User::factory()->create();
        $this->debtorCourse($user, 'Грамматика по Кочергиной гр.60', 5, 1); // долг по другому курсу
        $hindi = Course::factory()->create(['is_active' => true, 'title' => 'Хинди с нуля']);
        $full = Tariff::create(['course_id' => $hindi->id, 'title' => 'Весь курс', 'type' => 'full', 'price' => 30000, 'is_active' => true]);
        $this->assertNotNull($full->id);

        $link = $this->resolve($user, 'Добрый день! Хочу купить курс хинди, пришлите ссылку');

        $this->assertSame($hindi->id, $link['course']->id);
        $this->assertNull($link['block']);
        $this->assertSame(route('shop.course.show', $hindi->slug), $link['url']);
    }

    public function test_ambiguous_title_falls_back_to_debts_and_unsellable_course_is_ignored(): void
    {
        $user = User::factory()->create();
        $gr60 = $this->debtorCourse($user, 'Грамматика по Кочергиной гр.60', 3, 2); // долг — блок 3
        $this->debtorCourse(User::factory()->create(), 'Грамматика по Кочергиной гр.58', 3, 3);
        // Курс без активного тарифа купить нельзя — ссылку на него не даём.
        Course::factory()->create(['is_active' => true, 'title' => 'Ведийский язык']);

        // «грамматика кочергиной» подходит двум группам — не угадываем, идём по долгу.
        $this->assertSame($this->checkoutUrl($gr60, 3), $this->resolve($user, 'как оплатить грамматику Кочергиной?')['url']);
        $this->assertSame($this->checkoutUrl($gr60, 3), $this->resolve($user, 'хочу ведийский, как оплатить?')['url']);
    }

    public function test_group_without_block_tariff_links_the_course_page_and_unknown_group_gives_up(): void
    {
        $user = User::factory()->create();
        $gr55 = Course::factory()->create(['is_active' => true, 'title' => 'Грамматика гр.55']);

        $link = $this->resolve($user, 'как оплатить 7 блок, 55 группа');
        $this->assertSame($gr55->id, $link['course']->id);
        $this->assertNull($link['block']);
        $this->assertSame(route('shop.course.show', $gr55->slug), $link['url']);

        $this->assertNull($this->resolve($user, 'как оплатить 7 блок в 99й группе'));
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

    /**
     * Прод, 01-10-2026: «Перевела вам деньги для продолжение работы над
     * Рамаяной. Это октябрьской оплаты.» — стем «продо» из обычного слова
     * «продолжение» совпал с единственным курсом «…продолжающие», и шаблон D2
     * уехал со ссылкой на него. Слово не из названия курса курс не называет;
     * курс, названный по существенному слову, опознаётся по-прежнему.
     */
    public function test_generic_continue_word_does_not_guess_the_course(): void
    {
        $user = User::factory()->create();
        $hindi = Course::factory()->create(['is_active' => true, 'title' => 'Грамматика хинди гр. 4, суббота, продолжающие (2025)']);
        Tariff::create(['course_id' => $hindi->id, 'title' => 'Весь курс', 'type' => 'full', 'price' => 20000, 'is_active' => true]);

        $this->assertNull($this->resolve($user, 'Перевела вам деньги для продолжение работы над Рамаяной. Это октябрьской оплаты.'));
        $this->assertSame($hindi->id, $this->resolve($user, 'как оплатить курс хинди')['course']->id);
    }

    /**
     * Тот же класс, что «продо» (прод 01-10-2026): дни недели в названиях
     * групп («гр. 3, пятница», «гр. 4, суббота») — время занятий, не курс.
     * «В субботу» с единственным курсом с «суббота» в названии опознало
     * хинди-группу так же, как «продолжение» — «продолжающие».
     */
    public function test_day_words_do_not_guess_the_course(): void
    {
        $user = User::factory()->create();
        foreach (['Грамматика хинди гр. 3, пятница (2025)', 'Грамматика хинди гр. 4, суббота, продолжающие (2025)'] as $title) {
            $course = Course::factory()->create(['is_active' => true, 'title' => $title]);
            Tariff::create(['course_id' => $course->id, 'title' => 'Весь курс', 'type' => 'full', 'price' => 20000, 'is_active' => true]);
        }

        $this->assertNull($this->resolve($user, 'оплатить занятие в субботу'));
        $this->assertNull($this->resolve($user, 'не смогла в пятницу, как оплатить?'));
        // Название группы числом по-прежнему работает.
        $this->assertNotNull($this->resolve($user, 'как оплатить гр. 3?'));
    }

    /**
     * Прод, 02-10-2026: «Оплатила блоки 9-12» — номера блоков; но «занятие
     * в 15:00», дата «02.10» и ссылка на чек цифры блоков не называют.
     */
    public function test_times_dates_and_links_are_not_block_numbers(): void
    {
        $user = User::factory()->create();
        $this->debtorCourse($user, 'Лейтан', 5, 2); // долг — блоки 3–5

        $time = $this->resolve($user, 'занятие в 15:00 я не смогла, как оплатить?');
        $this->assertNull($time['block'], '«15:00» — это время, а не блок 15');

        $date = $this->resolve($user, 'оплата 02.10 прошла, как оплатить дальше?');
        $this->assertNull($date['block'], '«02.10» — это дата, а не блоки 2 и 10');

        $link = $this->resolve($user, 'чек: https://samskrte.ru/pay/15 — всё верно?');
        $this->assertNull($link['block'], 'число в ссылке — не номер блока');

        $this->assertSame(3, $this->resolve($user, 'как оплатить 3')['block']);
    }

    /**
     * Прод, 02-10-2026: курс не опознали — а студенту дважды ушло «Оплатить
     * курс «» удобнее из личного кабинета» со ссылкой на /login. Без курса
     * пустые кавычки схлопываются, {pay_link} ведёт в кабинет.
     */
    public function test_unresolved_course_collapses_quotes_and_links_the_cabinet(): void
    {
        $user = User::factory()->create(['name' => 'Студент Тест']);
        $template = new MessageTemplate(['body' => self::D2]);

        $text = $template->renderForSupport($user, 'как оплатить?');

        $this->assertStringNotContainsString('«»', $text);
        $this->assertStringContainsString('Оплатить курс удобнее', $text);
        $this->assertStringContainsString(route('student.access'), $text);
        $this->assertStringNotContainsString(url('/login'), $text);
    }
}
