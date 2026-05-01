<?php

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\CourseBlock;
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

        $this->get('/shop/course/test-course')->assertOk();
    }

    /** @test */
    public function hidden_course_returns_404(): void
    {
        Course::factory()->hidden()->create(['slug' => 'hidden-course']);

        $this->get('/shop/course/hidden-course')->assertNotFound();
    }

    /** @test */
    public function default_tab_is_blocks_when_course_has_a_current_block(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: 2);

        $html = $this->get('/shop/course/' . $course->slug)->getContent();

        $this->assertStringContainsString("tab: 'blocks'", $html);
    }

    /** @test */
    public function default_tab_is_full_when_no_block_is_current(): void
    {
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: null);

        $html = $this->get('/shop/course/' . $course->slug)->getContent();

        $this->assertStringContainsString("tab: 'full'", $html);
    }

    /** @test */
    public function current_block_renders_with_now_running_badge(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: 2);

        $html = $this->get('/shop/course/' . $course->slug)->getContent();

        $this->assertStringContainsString('СЕЙЧАС ИДЁТ', $html);
    }

    /** @test */
    public function current_block_appears_first_in_block_list(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = $this->makeCourseWithBlocks(blocks: 4, currentBlockNumber: 3);

        $html = $this->get('/shop/course/' . $course->slug)->getContent();

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

        $html = $this->get('/shop/course/' . $course->slug)->getContent();

        preg_match_all('/БЛОК\s*(\d+)/u', $html, $matches);
        $orderedNumbers = array_values(array_unique($matches[1]));

        $this->assertSame(['1', '2', '3', '4'], $orderedNumbers);
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
