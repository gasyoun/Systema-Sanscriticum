<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Волна 3 списка ожидания: порог голосов → payment_open (только при
 * достаточном прогнозе), оплаты → scheduled, лестница переносов.
 */
class WaitlistProcessTest extends TestCase
{
    use RefreshDatabase;

    private function item(array $attrs = []): CourseWaitlistItem
    {
        return CourseWaitlistItem::create(array_merge([
            'slug' => 'test-item-'.uniqid(),
            'course_title' => 'Тест',
            'teacher_name' => 'Тест',
            'min_payers' => 2,
            'kind' => 'other',
            'status' => 'collecting',
        ], $attrs));
    }

    private function vote(CourseWaitlistItem $item): void
    {
        $item->votes()->create(['user_id' => User::factory()->create()->id]);
    }

    public function test_threshold_met_and_forecast_sufficient_opens_payment(): void
    {
        $item = $this->item(['min_payers' => 2, 'block_price_rub' => 6000]);
        $this->vote($item);
        $this->vote($item);
        // 2 голоса × k=0.5 = 1 < 2 → НЕ открываем.
        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('collecting', $item->status);

        // +2 голоса = 4 × 0.5 = 2 >= 2 → payment_open.
        $this->vote($item);
        $this->vote($item);
        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('payment_open', $item->status);
    }

    public function test_enough_paid_creates_scheduled(): void
    {
        $course = Course::factory()->create();
        $item = $this->item([
            'status' => 'payment_open',
            'min_payers' => 2,
            'course_id' => $course->id,
        ]);
        foreach ([1, 2] as $i) {
            Payment::forceCreate([
                'user_id' => User::factory()->create()->id,
                'course_id' => $course->id,
                'amount' => 6000,
                'status' => 'paid',
            ]);
        }

        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('scheduled', $item->status);
    }

    public function test_postponed_by_ladder_grammar_january(): void
    {
        // Попытка октября (план 2026-10-15) провалилась, оплат нет.
        $item = $this->item([
            'status' => 'payment_open',
            'kind' => 'grammar',
            'min_payers' => 8,
            'earliest_start_at' => '2026-10-15',
            'planned_start_at' => '2026-10-15',
        ]);
        // Прогрев: дедлайн = план − 7 дней = 2026-10-08, поэтому замораживаем now на позже.
        $this->travelTo(Carbon::parse('2026-10-09'));

        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('postponed', $item->status);
        // Грамматика после октябрьской попытки → январь 2027.
        $this->assertSame('2027-01-01', $item->planned_start_at->toDateString());
        $this->assertSame(1, $item->start_attempts);
    }

    public function test_postponed_other_march(): void
    {
        $item = $this->item([
            'status' => 'payment_open',
            'kind' => 'other',
            'min_payers' => 8,
            'earliest_start_at' => '2026-10-15',
            'planned_start_at' => '2026-10-15',
        ]);
        $this->travelTo(Carbon::parse('2026-10-09'));
        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('2027-03-01', $item->planned_start_at->toDateString());
    }

    public function test_ladder_january_miss_goes_july(): void
    {
        $item = $this->item([
            'status' => 'payment_open',
            'kind' => 'other',
            'min_payers' => 8,
            'planned_start_at' => '2027-01-05',
            'start_attempts' => 1,
        ]);
        $this->travelTo(Carbon::parse('2027-01-01'));
        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('2027-07-01', $item->planned_start_at->toDateString());
    }

    public function test_ladder_july_miss_goes_september_same_year(): void
    {
        $item = $this->item([
            'status' => 'payment_open',
            'kind' => 'other',
            'min_payers' => 8,
            'planned_start_at' => '2027-07-05',
            'start_attempts' => 2,
        ]);
        $this->travelTo(Carbon::parse('2027-07-01'));
        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('2027-09-01', $item->planned_start_at->toDateString());
    }

    public function test_ladder_respects_earliest_start(): void
    {
        $item = $this->item([
            'status' => 'payment_open',
            'kind' => 'other',
            'min_payers' => 8,
            'planned_start_at' => '2026-10-15',
            'earliest_start_at' => '2028-06-01',
        ]);
        $this->travelTo(Carbon::parse('2026-10-09'));
        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        // Лестница: октябрь→март27→июль27→сент27→янв28→март28… earliest 2028-06 —
        // первый рунг после него: июль 2028.
        $this->assertSame('2028-07-01', $item->planned_start_at->toDateString());
        $this->assertGreaterThanOrEqual(1, $item->start_attempts);
    }

    public function test_sixteen_attempts_close(): void
    {
        $item = $this->item([
            'status' => 'payment_open',
            'kind' => 'other',
            'min_payers' => 8,
            'planned_start_at' => '2026-10-15',
            'start_attempts' => 16,
        ]);
        $this->travelTo(Carbon::parse('2026-10-09'));
        $this->artisan('waitlist:process')->assertSuccessful();
        $item->refresh();
        $this->assertSame('closed', $item->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $item = $this->item();
        $this->vote($item);
        $this->vote($item);
        $this->vote($item);
        $this->vote($item);
        $this->artisan('waitlist:process', ['--dry-run' => true])->assertSuccessful();
        $item->refresh();
        $this->assertSame('collecting', $item->status);
    }
}
