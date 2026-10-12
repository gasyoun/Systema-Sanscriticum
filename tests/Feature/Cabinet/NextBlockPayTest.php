<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\StudentDebtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MG 12-10-2026 — явная оплата следующего блока в кабинете.
 *
 * paidUntilForUser отдаёт next_block_pay (активный тариф block_N + цена как в
 * чекауте); кабинет рисует кнопку на «Сегодня» (гибрид), в зелёной сводке
 * легаси-дашборда, на «Моих оплатах» и в секции «Продление» на
 * «Оплата и доступ». Курс с долгом кнопку продления НЕ показывает — там свои
 * поблочные кнопки долга.
 */
class NextBlockPayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Курс без долга: блок 1 в прошлом, блок 2 идёт сейчас (оплачен), блок 3
     * впереди — с активным тарифом block_N. Кнопка продления = «Оплатить блок №3».
     */
    private function paidThroughCurrentBlock(bool $withTariff = true, bool $payCurrentBlock = true): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-next-block-pay']);
        $user->groups()->attach($group);
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Грамматика I']);
        $course->groups()->attach($group);

        CourseBlock::factory()->for($course)->withDates(now()->subWeeks(2), now()->subWeek())->create(['number' => 1]);
        CourseBlock::factory()->for($course)->withDates(now()->subDay(), now()->addDays(6))->create(['number' => 2]);
        CourseBlock::factory()->for($course)->withDates(now()->addWeek(), now()->addWeeks(2))->create(['number' => 3]);

        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4800, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1, 'is_conditional' => false,
        ]);
        if ($payCurrentBlock) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 4800, 'tariff' => 'block_2', 'status' => 'paid',
                'start_block' => 2, 'end_block' => 2, 'is_conditional' => false,
            ]);
        }

        if ($withTariff) {
            Tariff::factory()->block(3)->create(['course_id' => $course->id, 'price' => 4800]);
        }

        return compact('user', 'group', 'course');
    }

    /** @test */
    public function paid_until_includes_next_block_pay_option(): void
    {
        ['user' => $user, 'course' => $course] = $this->paidThroughCurrentBlock();

        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$course->id])
            ->get($course->id);

        $this->assertSame(2, (int) $paidUntil->block->number);
        $this->assertNotNull($paidUntil->next_block_pay);
        $this->assertSame(3, $paidUntil->next_block_pay['number']);
        $this->assertSame(4800.0, $paidUntil->next_block_pay['amount']);
        $this->assertStringContainsString('/checkout/', $paidUntil->next_block_pay['url']);
    }

    /** @test */
    public function next_block_pay_is_null_without_active_whole_block_tariff(): void
    {
        ['user' => $user, 'course' => $course] = $this->paidThroughCurrentBlock(withTariff: false);

        // Половинный тариф кнопку не даёт.
        Tariff::create([
            'course_id' => $course->id, 'title' => 'Блок 3 (первая половина)',
            'type' => 'block', 'block_number' => 3, 'block_half' => 1,
            'price' => 2400, 'is_active' => true,
        ]);

        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$course->id])
            ->get($course->id);

        $this->assertNull($paidUntil->next_block_pay);
    }

    /** @test */
    public function leaver_gets_summary_but_no_renewal_button(): void
    {
        // Кейс Горбуновой (12-10-2026): «Покинул» после блока 1 — «оплачено до»
        // остаётся честной сводкой, но кнопку «Оплатить блок №N» ушедшему не даём.
        ['user' => $user, 'course' => $course] = $this->paidThroughCurrentBlock();

        $user->courses()->syncWithoutDetaching([
            $course->id => ['status' => 'Покинул', 'joined_at_block' => 1, 'left_after_block' => 1],
        ]);

        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$course->id])
            ->get($course->id);

        $this->assertNotNull($paidUntil, 'Сводка «оплачено до» у ушедшего не исчезает.');
        $this->assertNull($paidUntil->next_block_pay, 'Кнопку продления ушедшему не показываем.');

        config(['features.cabinet_hybrid' => true]);
        $this->actingAs($user)
            ->get(route('student.access'))
            ->assertOk()
            ->assertDontSee('Продление', false)
            ->assertDontSee('Оплатить блок №3', false);
    }

    /** @test */
    public function renewal_stops_at_left_after_block_even_for_active_student(): void
    {
        // «Блок выхода» = 2: студент ещё «Записался», но дальше блока 2 оплат
        // не ждать — кнопки за его пределом нет.
        ['user' => $user, 'course' => $course] = $this->paidThroughCurrentBlock();

        $user->courses()->syncWithoutDetaching([
            $course->id => ['status' => 'Записался', 'left_after_block' => 2],
        ]);

        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$course->id])
            ->get($course->id);

        $this->assertNull($paidUntil->next_block_pay);
    }

    /** @test */
    public function active_student_without_exit_block_keeps_renewal_button(): void
    {
        // Наличие course_user-строки со статусом «Записался» без «Блока выхода»
        // кнопку не гасит (регресс-страховка на запрос pivot'а).
        ['user' => $user, 'course' => $course] = $this->paidThroughCurrentBlock();

        $user->courses()->syncWithoutDetaching([$course->id => ['status' => 'Записался']]);

        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$course->id])
            ->get($course->id);

        $this->assertNotNull($paidUntil->next_block_pay);
    }

    /** @test */
    public function hybrid_home_shows_next_block_pay_button(): void
    {
        config(['features.cabinet_hybrid' => true]);
        ['user' => $user] = $this->paidThroughCurrentBlock();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Оплачено до блока №2', false)
            ->assertSee('Оплатить блок №3', false)
            ->assertSee('4 800', false);
    }

    /** @test */
    public function hybrid_home_has_no_pay_button_when_tariff_missing(): void
    {
        config(['features.cabinet_hybrid' => true]);
        ['user' => $user] = $this->paidThroughCurrentBlock(withTariff: false);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Оплатить блок №', false);
    }

    /** @test */
    public function hybrid_home_has_no_pay_button_while_course_in_debt(): void
    {
        // Текущий блок не оплачен → курс в долгах: там свои поблочные кнопки
        // (вкладка долгов / «Оплата и доступ»), дублировать продление нельзя.
        config(['features.cabinet_hybrid' => true]);
        ['user' => $user] = $this->paidThroughCurrentBlock(payCurrentBlock: false);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('требует внимания по оплате', false)
            ->assertDontSee('Оплачено до блока №', false);
    }

    /** @test */
    public function hybrid_access_page_shows_renewals_section(): void
    {
        config(['features.cabinet_hybrid' => true]);
        ['user' => $user] = $this->paidThroughCurrentBlock();

        $this->actingAs($user)
            ->get(route('student.access'))
            ->assertOk()
            ->assertSee('Продление', false)
            ->assertSee('Грамматика I', false)
            ->assertSee('Оплатить блок №3', false);
    }

    /** @test */
    public function legacy_dashboard_shows_pay_button_in_paid_until_box(): void
    {
        config(['features.cabinet_hybrid' => false]);
        ['user' => $user] = $this->paidThroughCurrentBlock();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Оплачено до:', false)
            ->assertSee('Оплатить блок №3', false);
    }
}
