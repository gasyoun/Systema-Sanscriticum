<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\SupportAnswerSuggestion;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Support\SupportAnswerFactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H3999 (рулинг A1): резолвер остатка по оплате.
 *
 * Три вещи проверяются здесь и больше нигде:
 *  1. остаток берётся ИЗ {@see Tariff::calculateFinalPriceForUser()} — второй
 *     арифметики денег в поддержке не заводится;
 *  2. политика всегда draft_only — деньги не уходят студенту сами НИ ПРИ КАКОМ
 *     флаге (забор кодом, тест забора — SupportFactDraftOnlyFenceTest);
 *  3. цифра студента, не совпавшая с расчётной, переводит ответ в escalate.
 */
class SupportFactResolverBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: Course} */
    private function enrolledStudent(): array
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        return [$student, $course];
    }

    private function fullTariff(Course $course, int $price = 12000): Tariff
    {
        return Tariff::factory()->create([
            'course_id' => $course->id,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function paid(User $student, Course $course, string $tariff, int $amount): void
    {
        // withoutEvents: PaymentObserver раздаёт доступы, а здесь проверяется
        // только чтение денег — наблюдатель добавил бы шум в фикстуру.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => $tariff,
            'status' => 'paid',
            'is_conditional' => false,
        ]));
    }

    private function resolve(User $student, ?string $text = null): ?array
    {
        return app(SupportAnswerFactResolver::class)->resolve(
            SupportAnswerSuggestion::CATEGORY_PAYMENT,
            $student,
            $text,
        );
    }

    public function test_unpaid_course_names_the_full_tariff_price_as_remaining(): void
    {
        [$student, $course] = $this->enrolledStudent();
        $this->fullTariff($course);

        $resolved = $this->resolve($student, 'сколько я ещё должна?');

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('оплачено 0 ₽, к оплате остаётся 12 000 ₽', $resolved['draft']);
        $this->assertSame(SupportAnswerFactResolver::TYPE_BALANCE, $resolved['facts']['type']);
        $this->assertSame(0.0, $resolved['facts']['courses'][0]['paid']);
        $this->assertSame(12000.0, $resolved['facts']['courses'][0]['remaining']);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_owning_the_full_key_closes_the_balance(): void
    {
        [$student, $course] = $this->enrolledStudent();
        $this->fullTariff($course);
        $this->paid($student, $course, 'full', 12000);

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('оплачено 12 000 ₽, курс оплачен полностью', $resolved['draft']);
        $this->assertSame(0.0, $resolved['facts']['courses'][0]['remaining']);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_course_without_an_active_full_tariff_defers_to_the_curator(): void
    {
        [$student, $course] = $this->enrolledStudent();
        $this->paid($student, $course, 'block_1', 4800);

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('Остаток уточнит куратор', $resolved['draft']);
        $this->assertNull($resolved['facts']['courses'][0]['remaining']);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_a_figure_matching_the_computed_one_is_not_a_dispute(): void
    {
        [$student, $course] = $this->enrolledStudent();
        $this->fullTariff($course);
        $this->paid($student, $course, 'block_1', 4800);

        // Студент называет РАСЧЁТНЫЙ остаток — расхождения нет.
        $resolved = $this->resolve($student, 'мне сказали, что осталось 12000, верно?');

        $this->assertNotNull($resolved);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
        $this->assertArrayNotHasKey('claimed_amount', $resolved['facts']);
    }

    public function test_a_figure_matching_nothing_escalates_instead_of_answering(): void
    {
        [$student, $course] = $this->enrolledStudent();
        $this->fullTariff($course);
        $this->paid($student, $course, 'block_1', 4800);

        $resolved = $this->resolve($student, 'я оплатила 8 000, почему доступа нет?');

        $this->assertNotNull($resolved);
        $this->assertSame(SupportAnswerFactResolver::POLICY_ESCALATE, $resolved['send_policy']);
        $this->assertSame(8000.0, $resolved['facts']['claimed_amount']);
    }

    public function test_small_numbers_are_not_read_as_money(): void
    {
        [$student, $course] = $this->enrolledStudent();
        $this->fullTariff($course);

        // «3 блок» — не сумма. Без порога любой номер блока поднимал бы
        // расхождение и глушил бы ответ на ровном месте.
        $resolved = $this->resolve($student, 'за 3 блок я платила, а он закрыт');

        $this->assertNotNull($resolved);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_student_outside_any_active_group_gets_no_draft(): void
    {
        $student = User::factory()->create();

        $this->assertNull(
            $this->resolve($student, 'какой у меня остаток?'),
            'Активных групп нет — фактов о деньгах курса нет, черновик не создаётся.',
        );
    }
}
