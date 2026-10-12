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
