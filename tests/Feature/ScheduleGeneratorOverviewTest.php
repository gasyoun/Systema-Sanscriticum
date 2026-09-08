<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Schedule;
use App\Services\Schedule\DTO\GeneratorConfig;
use App\Services\Schedule\ScheduleGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4328: обзорное занятие в генераторе потока — отдельная is_overview-строка,
 * в нумерацию занятий не попадает.
 */
class ScheduleGeneratorOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function config(int $groupId, ?Carbon $overviewDate = null): GeneratorConfig
    {
        return new GeneratorConfig(
            groupId: $groupId,
            courseId: null,
            title: 'Тестовый поток',
            startDate: Carbon::parse('2026-03-07'), // суббота
            startTime: '11:00',
            durationMinutes: 90,
            totalLessons: 3,
            startNumber: 1,
            startLessonIndex: 1,
            weekdays: [6], // суббота
            template: '{TITLE} (#{N}, {DATE}) | {BN}-е занятие {BLOCK}-го блока',
            skipDates: [],
            addDates: [],
            link: null,
            preserve: false,
            overviewDate: $overviewDate,
        );
    }

    /** @test */
    public function it_creates_overview_row_not_counted_in_lessons(): void
    {
        $group = Group::factory()->create();

        $created = app(ScheduleGenerator::class)
            ->generate($this->config($group->id, Carbon::parse('2026-02-28')));

        $this->assertSame(4, $created->count()); // 3 занятия + обзорное

        $overview = Schedule::where('group_id', $group->id)->where('is_overview', true)->get();
        $this->assertSame(1, $overview->count());
        $this->assertSame('Обзорное занятие', $overview->first()->title);
        $this->assertSame('2026-02-28 11:00', $overview->first()->start->format('Y-m-d H:i'));

        $lessons = Schedule::where('group_id', $group->id)->where('is_overview', false)->orderBy('start')->get();
        $this->assertSame(3, $lessons->count());
        $this->assertSame('2026-03-07 11:00', $lessons->first()->start->format('Y-m-d H:i'));
    }

    /** @test */
    public function no_overview_row_without_date(): void
    {
        $group = Group::factory()->create();

        $created = app(ScheduleGenerator::class)->generate($this->config($group->id));

        $this->assertSame(3, $created->count());
        $this->assertSame(0, Schedule::where('group_id', $group->id)->where('is_overview', true)->count());
    }

    /** @test */
    public function regeneration_with_preserve_replaces_future_overview(): void
    {
        $group = Group::factory()->create();

        app(ScheduleGenerator::class)->generate($this->config($group->id, Carbon::parse('2026-02-28')));

        // Перегенерация (preserve=false удаляет все строки группы и создаёт
        // заново): прошлое обзорное удаляется, новое — с новой датой.
        $config = new GeneratorConfig(
            groupId: $group->id,
            courseId: null,
            title: 'Тестовый поток',
            startDate: Carbon::parse('2026-03-07'),
            startTime: '11:00',
            durationMinutes: 90,
            totalLessons: 3,
            startNumber: 1,
            startLessonIndex: 1,
            weekdays: [6],
            template: '{TITLE} (#{N}, {DATE}) | {BN}-е занятие {BLOCK}-го блока',
            skipDates: [],
            addDates: [],
            link: null,
            preserve: false,
            overviewDate: Carbon::parse('2026-02-27'),
        );

        app(ScheduleGenerator::class)->generate($config);

        $overviews = Schedule::where('group_id', $group->id)->where('is_overview', true)->get();
        $this->assertSame(1, $overviews->count(), 'Прошлое обзорное удалено, создано новое');
        $this->assertSame('2026-02-27 11:00', $overviews->first()->start->format('Y-m-d H:i'));
    }
}
