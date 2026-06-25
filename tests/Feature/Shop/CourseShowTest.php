<?php

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CourseShowTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function visible_course_page_returns_ok(): void
    {
        $course = Course::factory()->create(['slug' => 'test-course']);
        Tariff::factory()->for($course)->create(['title' => 'Весь курс целиком']);

        $this->get('/online/kursy/test-course')->assertOk();
    }

    /** @test */
    public function hidden_course_returns_404(): void
    {
        Course::factory()->hidden()->create(['slug' => 'hidden-course']);

        $this->get('/online/kursy/hidden-course')->assertNotFound();
    }

    /** @test */
    public function default_tab_is_blocks_when_course_has_a_current_block(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: 2);

        $html = $this->get('/online/kursy/'.$course->slug)->getContent();

        $this->assertStringContainsString("tab: 'blocks'", $html);
    }

    /** @test */
    public function default_tab_is_full_when_no_block_is_current(): void
    {
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: null);

        $html = $this->get('/online/kursy/'.$course->slug)->getContent();

        $this->assertStringContainsString("tab: 'full'", $html);
    }

    /** @test */
    public function current_block_renders_with_now_running_badge(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: 2);

        $html = $this->get('/online/kursy/'.$course->slug)->getContent();

        $this->assertStringContainsString('СЕЙЧАС ИДЁТ', $html);
    }

    /** @test */
    public function current_block_appears_first_in_block_list(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: 3);

        $html = $this->get('/online/kursy/'.$course->slug)->getContent();

        // В разметке "БЛОК N" встречается у каждой карточки. Третий блок (актуальный)
        // должен идти раньше всех остальных в HTML.
        preg_match_all('/БЛОК\s*(\d+)/u', $html, $matches);
        $orderedNumbers = array_values(array_unique($matches[1]));

        // Первое появление — актуальный блок, дальше — остальные по возрастанию
        $this->assertSame(['3', '1', '2', '4'], $orderedNumbers);
    }

    /** @test */
    public function current_block_does_not_appear_first_when_none_is_current(): void
    {
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: null);

        $html = $this->get('/online/kursy/'.$course->slug)->getContent();

        preg_match_all('/БЛОК\s*(\d+)/u', $html, $matches);
        $orderedNumbers = array_values(array_unique($matches[1]));

        $this->assertSame(['1', '2', '3', '4'], $orderedNumbers);
    }

    /** @test */
    public function schedule_section_lists_upcoming_session_for_the_course(): void
    {
        Carbon::setTestNow('2026-06-25 12:00:00');
        $course = Course::factory()->create(['slug' => 'sched-course']);
        Tariff::factory()->for($course)->create();

        Schedule::create([
            'title' => 'Вводное занятие',
            'start' => Carbon::parse('2026-06-28 20:00:00'),
            'end' => Carbon::parse('2026-06-28 22:00:00'),
            'course_id' => $course->id,
        ]);

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('Расписание')
            ->assertSee('Вводное занятие')
            ->assertSee('20:00–22:00');
    }

    /** @test */
    public function schedule_section_includes_sessions_linked_through_a_group(): void
    {
        Carbon::setTestNow('2026-06-25 12:00:00');
        $course = Course::factory()->create(['slug' => 'sched-group-course']);
        Tariff::factory()->for($course)->create();
        $group = Group::create(['name' => 'Поток 1']);
        $course->groups()->attach($group);

        Schedule::create([
            'title' => 'Занятие группы',
            'start' => Carbon::parse('2026-07-01 18:00:00'),
            'end' => Carbon::parse('2026-07-01 20:00:00'),
            'group_id' => $group->id,
        ]);

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('Занятие группы');
    }

    /** @test */
    public function past_sessions_are_not_shown(): void
    {
        Carbon::setTestNow('2026-06-25 12:00:00');
        $course = Course::factory()->create(['slug' => 'past-sched-course']);
        Tariff::factory()->for($course)->create();

        Schedule::create([
            'title' => 'Прошедшее занятие',
            'start' => Carbon::parse('2026-06-20 20:00:00'),
            'end' => Carbon::parse('2026-06-20 22:00:00'),
            'course_id' => $course->id,
        ]);

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertDontSee('Прошедшее занятие');
    }

    /** @test */
    public function schedule_section_is_hidden_when_course_has_no_sessions(): void
    {
        $course = Course::factory()->create(['slug' => 'no-sched-course']);
        Tariff::factory()->for($course)->create();

        $html = $this->get('/online/kursy/'.$course->slug)->getContent();

        // Заголовок секции расписания не должен появляться без занятий.
        $this->assertStringNotContainsString('id="schedule"', $html);
    }

    /** Создать курс с N блок-тарифами + опционально пометить один блок текущим. */
    private function makeCourseWithBlocks(int $blocks, ?int $currentBlockNumber): Course
    {
        $course = Course::factory()->create();
        // полный тариф для верности
        Tariff::factory()->for($course)->create();

        for ($i = 1; $i <= $blocks; $i++) {
            $block = CourseBlock::factory()
                ->for($course)
                ->state(fn () => [
                    'number' => $i,
                    'is_current' => $currentBlockNumber === $i,
                ])
                ->create();

            Tariff::factory()
                ->for($course)
                ->block($i)
                ->create(['course_block_id' => $block->id]);
        }

        return $course->fresh();
    }
}
