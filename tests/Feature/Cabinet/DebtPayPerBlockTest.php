<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\DebtPaymentResolver;
use App\Services\StudentDebtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * features.debt_pay_per_block (30-09-2026): должник по нескольким блокам гасит
 * долг всё разом ИЛИ по одному блоку. Кнопки блоков ведут на штатный чекаут
 * тарифа block_N — новый платёжный путь не вводится, доступ выдаёт обычный
 * PaymentObserver → grantAccess.
 */
class DebtPayPerBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.cabinet_hybrid' => true]);
    }

    /**
     * Курс блоков 1–5 (текущий 5); оплачены 1 и 2 → долг = блоки 3, 4, 5.
     * Тарифы на 3 и 4 (4 000 и 5 000 ₽); у блока 5 тарифа нет, если $priceAll = false.
     */
    private function debtorCourse(User $user, bool $priceAll = true): Course
    {
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Синтаксис санскрита']);
        foreach ([1, 2, 3, 4] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }
        CourseBlock::factory()->for($course)->current()->create(['number' => 5]);

        foreach ([1, 2] as $n) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 4000, 'tariff' => 'block_'.$n, 'status' => 'paid',
                'start_block' => $n, 'end_block' => $n, 'is_conditional' => false,
            ]);
        }
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 3', 'type' => 'block', 'block_number' => 3, 'price' => 4000, 'is_active' => true]);
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 4', 'type' => 'block', 'block_number' => 4, 'price' => 5000, 'is_active' => true]);
        if ($priceAll) {
            Tariff::create(['course_id' => $course->id, 'title' => 'Блок 5', 'type' => 'block', 'block_number' => 5, 'price' => 6000, 'is_active' => true]);
        }

        return $course;
    }

    private function blockTariff(Course $course, int $n): Tariff
    {
        return Tariff::where('course_id', $course->id)->where('block_number', $n)->firstOrFail();
    }

    public function test_resolver_exposes_per_block_amount(): void
    {
        $user = User::factory()->create();
        $course = $this->debtorCourse($user);

        $debt = app(StudentDebtsService::class)->forUser($user)->firstWhere('course_id', $course->id);
        $opts = app(DebtPaymentResolver::class)->optionsFor($debt, $user);

        $this->assertSame([4000.0, 5000.0, 6000.0], array_column($opts['blocks'], 'amount'));
        $this->assertSame(15000.0, $opts['bundle']['amount']);
    }

    public function test_flag_off_keeps_cabinet_as_before(): void
    {
        config(['features.debt_pay_per_block' => false]);
        $user = User::factory()->create();
        $this->debtorCourse($user);

        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Оплатить блоки')
            ->assertDontSee('Оплатить все блоки')
            ->assertDontSee('Оплатить по одному блоку');

        $this->actingAs($user)->get(route('student.access'))
            ->assertOk()
            ->assertDontSee('data-testid="debt-pay-per-block"', false)
            ->assertDontSee('Блок №3');
    }

    public function test_flag_on_access_page_offers_bundle_and_each_block_checkout(): void
    {
        config(['features.debt_pay_per_block' => true]);
        $user = User::factory()->create();
        $course = $this->debtorCourse($user);

        $response = $this->actingAs($user)->get(route('student.access'))->assertOk();

        $response->assertSee('id="debt-course-'.$course->id.'"', false);
        $response->assertSee('Оплатить все блоки — 15 000 ₽');
        $response->assertSee('Или по одному блоку');
        foreach ([3 => '4 000', 4 => '5 000', 5 => '6 000'] as $n => $price) {
            $response->assertSee('Блок №'.$n.' — '.$price.' ₽');
            $response->assertSee('href="'.route('checkout.show', $this->blockTariff($course, $n)).'"', false);
        }
    }

    public function test_flag_on_home_card_links_to_per_block_payment(): void
    {
        config(['features.debt_pay_per_block' => true]);
        $user = User::factory()->create();
        $course = $this->debtorCourse($user);

        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Оплатить все блоки')
            ->assertSee('Оплатить по одному блоку')
            ->assertSee('href="'.route('student.access').'#debt-course-'.$course->id.'"', false);
    }

    public function test_block_without_tariff_goes_to_curator_not_a_button(): void
    {
        config(['features.debt_pay_per_block' => true]);
        $user = User::factory()->create();
        $this->debtorCourse($user, priceAll: false);

        $this->actingAs($user)->get(route('student.access'))
            ->assertOk()
            ->assertSee('Блок №3 — 4 000 ₽')
            ->assertSee('Блок №4 — 5 000 ₽')
            ->assertDontSee('Блок №5 —')
            ->assertSee('Блоки №5 — оплата через', false)
            // Бандл требует тарифа на каждый блок долга — без блока 5 его нет.
            ->assertDontSee('Оплатить все блоки');
    }

    public function test_paying_one_block_tariff_opens_only_that_block_and_keeps_the_rest_in_debt(): void
    {
        $user = User::factory()->create();
        $course = $this->debtorCourse($user);
        $lesson3 = Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 3]);
        $lesson4 = Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 4]);

        // Оплата по кнопке «Блок №3» = обычный платёж тарифа block_3 (штатный observer).
        $tariff = $this->blockTariff($course, 3);
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => $tariff->accessKey(), 'status' => 'paid',
            'start_block' => 3, 'end_block' => 3, 'is_conditional' => false,
        ]);

        $owned = Payment::where('user_id', $user->id)->where('course_id', $course->id)->paid()->pluck('tariff')->all();
        $this->assertTrue($lesson3->isUnlockedBy($owned));
        $this->assertFalse($lesson4->isUnlockedBy($owned));

        $debt = app(StudentDebtsService::class)->forUser($user)->firstWhere('course_id', $course->id);
        $this->assertSame([4, 5], $debt->debt_block_numbers);
    }
}
