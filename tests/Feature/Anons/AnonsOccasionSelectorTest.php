<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Services\Anons\AnonsOccasionSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * H6329: отбор поводов анонс-кампании по kind. Схема фикстур — как в
 * PublicScheduleFeedTest (ScheduleFactory в репозитории нет): курс с
 * недельным ритмом («обычное»), курс без ритма — 3 занятия в разные дни
 * недели → cadence=null («разовое», семантика H6313), обзорная строка
 * is_overview («обзорное»). Канон: docs/SCHEDULE_KINDS_CANON_ANONS_SITE.
 */
final class AnonsOccasionSelectorTest extends TestCase
{
    use RefreshDatabase;

    private AnonsOccasionSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new AnonsOccasionSelector;
        // Фид кэшируется на 5 минут — между тестами кэш сбрасываем.
        Cache::flush();
    }

    private function makeCourse(string $title): Course
    {
        $course = Course::factory()->create(['title' => $title, 'is_visible' => true]);
        $group = Group::factory()->create();
        $group->courses()->attach($course->id);

        return $course;
    }

    private function addSession(Course $course, string $start, bool $overview = false): Schedule
    {
        $group = $course->groups->first();

        return Schedule::create([
            'title' => $course->title.' — занятие',
            'group_id' => $group->id,
            'course_id' => $course->id,
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($start)->addHours(2),
            'is_overview' => $overview,
        ]);
    }

    /** Свежая фикстура: разовый курс (3 строка) + обычный (2) + обзорное (1). */
    private function seedFixture(): void
    {
        $open = $this->makeCourse('Открытые занятия и вебинары');
        $this->addSession($open, '2027-02-01 18:00');
        $this->addSession($open, '2027-02-03 18:00');
        $this->addSession($open, '2027-02-05 18:00');

        $regular = $this->makeCourse('Введение в индийскую философию');
        $this->addSession($regular, '2027-01-04 11:00');
        $this->addSession($regular, '2027-01-11 11:00');
        // Обзорное в счёт ритма не входит: курс остаётся «обычным».
        $this->addSession($regular, '2027-01-02 11:00', overview: true);
    }

    public function test_kinds_partition_upcoming_sessions(): void
    {
        $this->seedFixture();

        $this->assertCount(6, $this->selector->select()->all());

        $irregular = $this->selector->select('разовое');
        $this->assertCount(3, $irregular);
        $this->assertSame('разовое', $irregular[0]['kind']);
        $this->assertSame('Открытые занятия и вебинары', $irregular[0]['title']);

        $regular = $this->selector->select('обычное');
        $this->assertCount(2, $regular);
        $this->assertSame('обычное', $regular[0]['kind']);

        $overview = $this->selector->select('обзорное');
        $this->assertCount(1, $overview);
        $this->assertSame('обзорное', $overview[0]['kind']);
        $this->assertSame('Введение в индийскую философию', $overview[0]['title']);
    }

    public function test_mixed_course_is_not_irregular(): void
    {
        // 2 разных дня + 1 время → ритм-строка строится → курс «обычное»,
        // даже при одной разовой на вид строке.
        $mixed = $this->makeCourse('Смешанный курс');
        $this->addSession($mixed, '2027-03-01 11:00');
        $this->addSession($mixed, '2027-03-08 11:00');
        $this->addSession($mixed, '2027-03-10 11:00');

        $kinds = $this->selector->select()->where('course_slug', $mixed->slug)->pluck('kind')->all();
        $this->assertSame(['обычное', 'обычное', 'обычное'], $kinds);
    }

    /** Согласованность с фидом H6313: kind каждой строки фида = kind отбора. */
    public function test_kind_matches_public_feed(): void
    {
        $this->seedFixture();

        $feed = $this->getJson('/api/public/schedule')->assertOk()->json('data');

        $byOccasion = [];
        foreach ($this->selector->select() as $occasion) {
            $byOccasion[$occasion['course_slug'].'#'.$occasion['start']] = $occasion['kind'];
        }

        $this->assertSame([], array_diff(
            array_map(fn (array $row): string => $row['course']['slug'].'#'.$row['start'], $feed),
            array_keys($byOccasion),
        ));
        foreach ($feed as $row) {
            $this->assertSame(
                $byOccasion[$row['course']['slug'].'#'.$row['start']],
                $row['kind'],
                'kind строки фида и отбора разошлись: '.$row['course']['slug'],
            );
        }
    }

    public function test_unknown_kind_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->selector->select('вебинар');
    }

    public function test_selection_is_reproducible(): void
    {
        $this->seedFixture();

        $first = $this->selector->select('разовое')->all();
        $second = $this->selector->select('разовое')->all();

        $this->assertSame($first, $second);
    }

    public function test_plan_occasions_command_lists_and_refuses(): void
    {
        $this->seedFixture();

        $this->artisan('anons:plan-occasions', ['--kind' => 'разовое', '--json' => true])
            ->expectsOutputToContain('"count": 3')
            ->assertExitCode(0);

        $this->artisan('anons:plan-occasions', ['--kind' => 'вебинар'])
            ->assertExitCode(1);
    }
}
