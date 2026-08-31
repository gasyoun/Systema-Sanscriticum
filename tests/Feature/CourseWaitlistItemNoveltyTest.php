<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Волна 1 списка ожидания: novelty на Course (модель/фид расписания) и
 * прогноз оплат CourseWaitlistItem::forecastPayments (гипотезные коэффициенты).
 */
class CourseWaitlistItemNoveltyTest extends TestCase
{
    use RefreshDatabase;

    public function test_novelty_helpers_and_defaults(): void
    {
        $new = Course::factory()->create(['novelty' => 'new']);
        $repeat = Course::factory()->create(['novelty' => 'repeat']);
        $noRepeat = Course::factory()->create(['novelty' => 'no_repeat']);
        $usual = Course::factory()->create(['novelty' => 'usual']);

        $this->assertTrue($new->isNewForAnnouncements());
        $this->assertTrue($repeat->isNewForAnnouncements());
        $this->assertFalse($noRepeat->isNewForAnnouncements());
        $this->assertFalse($usual->isNewForAnnouncements());
        $this->assertSame('Впервые', $new->noveltyLabel());
        $this->assertSame('Обычный', $usual->noveltyLabel());
    }

    public function test_public_schedule_feed_carries_novelty(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'novelty' => 'repeat',
            'teacher_id' => null,
        ]);

        // Курсы без групп не попадают в фид расписания (нужен upcoming schedule),
        // поэтому создаём строку расписания вручную, как в PublicScheduleFeedTest.
        $group = \App\Models\Group::factory()->create(['status' => 'forming']);
        $group->courses()->attach($course->id);
        \App\Models\Schedule::create([
            'title' => $course->title.' — занятие',
            'group_id' => $group->id,
            'course_id' => $course->id,
            'start' => now()->addDays(2)->setTime(18, 0),
            'end' => now()->addDays(2)->setTime(20, 0),
        ]);

        $row = $this->getJson('/api/public/schedule')->assertOk()->json('data.0');
        $this->assertSame('repeat', $row['novelty']);
    }

    public function test_forecast_payments_scales_votes_with_hypothesis_k(): void
    {
        $item = CourseWaitlistItem::create([
            'slug' => 'forecast-test-1',
            'course_title' => 'Тест',
            'teacher_name' => 'Тест',
            'min_payers' => 8,
            'kind' => 'other',
        ]);

        // 4 голоса × k=0.5 → 2±0.
        foreach (range(1, 4) as $i) {
            $item->votes()->create(['user_id' => User::factory()->create()->id]);
        }
        $this->assertSame(['low' => 2, 'high' => 2], $item->forecastPayments());

        // Повторный поток с историей: потолок high = 60 % прошлого набора.
        $item->update(['historical_paid_n' => 50]);
        $forecast = $item->forecastPayments();
        $this->assertLessThanOrEqual(30, $forecast['high']);
    }

    public function test_threshold_helpers(): void
    {
        $item = CourseWaitlistItem::create([
            'slug' => 'threshold-test',
            'course_title' => 'Тест',
            'teacher_name' => 'Тест',
            'min_payers' => 2,
        ]);

        $item->votes()->create(['user_id' => User::factory()->create()->id]);
        $this->assertFalse($item->hasThreshold());

        $item->votes()->create(['user_id' => User::factory()->create()->id]);
        $this->assertTrue($item->hasThreshold());
    }
}