<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Services\Schedule\FullSchedulePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FullSchedulePostTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_renders_mg_exact_format_with_overview_and_groups_of_four(): void
    {
        $group = Group::factory()->create(['name' => 'Индийская философия 2026']);

        // Обзорное 28.02.2026 (сб) 11:00 + 16 занятий по субботам с 07.03.
        Schedule::create([
            'title' => 'Обзорное занятие',
            'start' => Carbon::parse('2026-02-28 11:00'),
            'group_id' => $group->id,
            'is_overview' => true,
        ]);

        $saturday = Carbon::parse('2026-03-07 11:00');
        for ($i = 0; $i < 16; $i++) {
            Schedule::create([
                'title' => 'Занятие #'.($i + 1),
                'start' => $saturday->copy()->addWeeks($i),
                'group_id' => $group->id,
            ]);
        }

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);

        $text = $post->text();

        // Заголовок + ритм-строка (авто из расписания).
        $this->assertStringContainsString('Расписание курса «'.$group->name.'»', $text);
        $this->assertStringContainsString('Еженедельно по субботам в 11:00 (по МСК)', $text);

        // Обзорное: отдельный блок, «не в счет 16», до 1-го занятия.
        $this->assertStringContainsString("Обзорное занятие (не в счет 16):\n28 февраля 2026 (суббота), 11:00", $text);
        $this->assertLessThan(strpos($text, '1-е занятие'), strpos($text, 'Обзорное занятие'));

        // Формат дат: родительный падеж месяца, именительный день недели.
        $this->assertStringContainsString('1-е занятие: 7 марта 2026 (суббота), 11:00', $text);
        $this->assertStringContainsString('4-е занятие: 28 марта 2026 (суббота), 11:00', $text);
        $this->assertStringContainsString('5-е занятие: 4 апреля 2026 (суббота), 11:00', $text);
        $this->assertStringContainsString('16-е занятие: 20 июня 2026 (суббота), 11:00', $text);

        // Пустая строка после каждого 4-го занятия (блоки по 4).
        $this->assertStringContainsString("4-е занятие: 28 марта 2026 (суббота), 11:00\n\n5-е занятие", $text);
        $this->assertStringContainsString("8-е занятие: 25 апреля 2026 (суббота), 11:00\n\n9-е занятие", $text);

        // Обзорное НЕ нумеруется: занятий ровно 16, последнее — 16-е.
        $this->assertStringNotContainsString('17-е занятие', $text);
    }

    /** @test */
    public function it_starts_with_first_lesson_when_no_overview(): void
    {
        // Решение MG: обзорного нет, первое = пробное (или пробного нет вовсе) —
        // пост начинается сразу с «1-е занятие», без пометок.
        $group = Group::factory()->create();

        Schedule::create(['title' => 'Занятие 1', 'start' => Carbon::parse('2027-03-06 19:00'), 'group_id' => $group->id]);
        Schedule::create(['title' => 'Занятие 2', 'start' => Carbon::parse('2027-03-13 19:00'), 'group_id' => $group->id]);

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);

        $text = $post->text();
        $this->assertStringNotContainsString('Обзорное занятие', $text);
        $this->assertStringContainsString('1-е занятие: 6 марта 2027 (суббота), 19:00', $text);
        $this->assertSame(0, substr_count($text, 'не в счет'));
    }

    /** @test */
    public function it_builds_two_weekday_cadence_line(): void
    {
        $group = Group::factory()->create();

        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-03 19:00'), 'group_id' => $group->id]); // Вт
        Schedule::create(['title' => 'B', 'start' => Carbon::parse('2026-03-05 19:00'), 'group_id' => $group->id]); // Чт
        Schedule::create(['title' => 'C', 'start' => Carbon::parse('2026-03-10 19:00'), 'group_id' => $group->id]); // Вт

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);
        $this->assertStringContainsString('Еженедельно по вторникам и четвергам в 19:00 (по МСК)', $post->text());
    }

    /** @test */
    public function it_renders_telegram_html_with_bold_lessons_and_safe_escaping(): void
    {
        $group = Group::factory()->create();

        Schedule::create(['title' => 'Обзорное', 'start' => Carbon::parse('2026-02-28 11:00'), 'group_id' => $group->id, 'is_overview' => true]);
        Schedule::create(['title' => '1', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);

        $html = $post->telegramHtml();

        // Заголовок и ритм — без <b>.
        $this->assertStringContainsString('Расписание курса', $html);
        $this->assertStringNotContainsString('<b>Расписание курса', $html);
        $this->assertStringNotContainsString('<b>Еженедельно', $html);

        // Обзорное и занятия — метка жирным, дата обычным.
        $this->assertStringContainsString('<b>Обзорное занятие (не в счет 1)</b>:', $html);
        $this->assertStringContainsString('<b>1-е занятие</b>: 7 марта 2026 (суббота), 11:00', $html);
        // Дата в строке — БЕЗ жирного (правка MG 08-09-2026).
        $this->assertStringNotContainsString('<b>7 марта 2026', $html);
        $this->assertStringNotContainsString('<b>28 февраля 2026', $html);
        $this->assertStringNotContainsString('<b></b>', $html);
    }

    /** @test */
    public function site_html_bolds_only_title_and_labels(): void
    {
        $group = Group::factory()->create();

        Schedule::create(['title' => 'Обзорное', 'start' => Carbon::parse('2026-02-28 11:00'), 'group_id' => $group->id, 'is_overview' => true]);
        Schedule::create(['title' => '1', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);

        $html = $post->html();

        // Жирным — только заголовок курса и метки; ритм-строка и даты обычным.
        $this->assertStringContainsString('<strong>Расписание курса', $html);
        $this->assertStringNotContainsString('<strong>Еженедельно', $html);
        $this->assertStringContainsString('<strong>Обзорное занятие (не в счет 1)</strong>:', $html);
        $this->assertStringContainsString('<strong>1-е занятие</strong>: 7 марта 2026 (суббота), 11:00', $html);
        $this->assertStringNotContainsString('<strong>7 марта 2026', $html);
        $this->assertStringNotContainsString('<strong>28 февраля 2026', $html);
    }

    /** @test */
    public function it_suffixes_group_name_only_for_multi_group_course(): void
    {
        $course = Course::factory()->create(['title' => 'Курс X']);
        $g1 = Group::factory()->create();
        $g2 = Group::factory()->create();
        $course->groups()->attach([$g1->id, $g2->id]);

        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $g1->id, 'course_id' => $course->id]);
        Schedule::create(['title' => 'B', 'start' => Carbon::parse('2026-03-08 19:00'), 'group_id' => $g2->id, 'course_id' => $course->id]);

        $posts = FullSchedulePost::forCourse($course);
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('Курс X» — группа '.$g1->name, $posts[0]->text());
        $this->assertStringContainsString('Курс X» — группа '.$g2->name, $posts[1]->text());

        // Одногрупповой курс — заголовок без суффикса.
        $single = Course::factory()->create(['title' => 'Курс Y']);
        $g = Group::factory()->create();
        $single->groups()->attach($g->id);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $g->id]);

        $posts = FullSchedulePost::forCourse($single);
        $this->assertCount(1, $posts);
        $this->assertStringNotContainsString(' — группа', $posts[0]->text());
    }

    /** @test */
    public function it_returns_null_for_group_without_schedules(): void
    {
        $group = Group::factory()->create();
        $this->assertNull(FullSchedulePost::forGroup($group));
    }
}
