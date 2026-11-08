<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Course296ScheduleRepairTest extends TestCase
{
    use RefreshDatabase;

    private const DELTA_SECONDS = 105 * 86400 - 59;

    protected function setUp(): void
    {
        parent::setUp();
        Course::factory()->create(['id' => 296]);
    }

    public function test_dry_run_checks_all_57_rows_without_writing(): void
    {
        $this->seedIncidentRows();
        $before = Schedule::find(2223)->start->copy();

        $exit = Artisan::call('schedules:repair-course-296-block-end-shift');

        $this->assertSame(0, $exit);
        $this->assertSame($before->toDateTimeString(), Schedule::find(2223)->start->toDateTimeString());
        $this->assertStringContainsString('Dry-run: 57 строк проверены', Artisan::output());
    }

    public function test_apply_restores_exact_dates_and_preserves_titles(): void
    {
        $this->seedIncidentRows();

        $exit = Artisan::call('schedules:repair-course-296-block-end-shift', ['--apply' => true]);

        $this->assertSame(0, $exit);
        $first = Schedule::find(2223);
        $last = Schedule::find(2279);
        $this->assertSame('2026-10-27 10:00:00', $first->start->toDateTimeString());
        $this->assertSame('2026-10-27 12:00:00', $first->end->toDateTimeString());
        $this->assertSame('Грамматика по Кочергиной гр.50 (#63, 27.10.26)', $first->title);
        $this->assertSame('2027-11-23 10:00:00', $last->start->toDateTimeString());
    }

    public function test_apply_refuses_whole_set_when_one_row_drifted(): void
    {
        $this->seedIncidentRows();
        $before = Schedule::find(2223)->start->copy();
        DB::table('schedules')->where('id', 2279)->update([
            'start' => Schedule::find(2279)->start->copy()->addSecond(),
        ]);

        $exit = Artisan::call('schedules:repair-course-296-block-end-shift', ['--apply' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame($before->toDateTimeString(), Schedule::find(2223)->start->toDateTimeString());
        $this->assertStringContainsString('не совпадает с отпечатком инцидента', Artisan::output());
    }

    private function seedIncidentRows(): void
    {
        $now = now();

        for ($i = 0; $i < 57; $i++) {
            $number = 63 + $i;
            $intended = Carbon::parse('2026-10-27 10:00:00', 'Europe/Moscow')->addWeeks($i);
            $start = $intended->copy()->addSeconds(self::DELTA_SECONDS);

            DB::table('schedules')->insert([
                'id' => 2223 + $i,
                'title' => sprintf('Грамматика по Кочергиной гр.50 (#%d, %s)', $number, $intended->format('d.m.y')),
                'course_id' => 296,
                'start' => $start,
                'end' => $start->copy()->addHours(2),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
