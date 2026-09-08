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

    /**
     * H4387: прошедшие скрыты по умолчанию, статус-строка и кнопка на месте,
     * последнее прошедшее подсвечено жёлтым, будущее видно.
     *
     * @test
     */
    public function html_hides_past_by_default_and_highlights_last_past(): void
    {
        $group = Group::factory()->create();

        Schedule::create(['title' => 'A', 'start' => now()->subDays(21)->format('Y-m-d H:i:s'), 'group_id' => $group->id]);
        Schedule::create(['title' => 'B', 'start' => now()->subDays(14)->format('Y-m-d H:i:s'), 'group_id' => $group->id]);
        Schedule::create(['title' => 'C', 'start' => now()->addDays(7)->format('Y-m-d H:i:s'), 'group_id' => $group->id]);

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);
        $this->assertSame(2, $post->pastCount);

        $html = $post->html();

        // Статус + кнопка + контейнер в режиме скрытия.
        $this->assertMatchesRegularExpression('/<p class="fs-status">Прошло занятий: 2 · последнее: [^<]+<\/p>/', $html);
        $this->assertStringContainsString('<button type="button" class="fs-toggle" aria-expanded="false">Показать прошедшие занятия</button>', $html);
        $this->assertStringContainsString('<div class="fs-body" data-fs-past="hidden">', $html);

        // Прошедшие скрыты; последнее прошедшее (2-е) — жёлтое; перед 1-м
        // занятием внутри скрытого span'а лежит перенесённый разрыв.
        $this->assertMatchesRegularExpression('/<span class="fs-line fs-past" hidden>(<br>\s*)?<strong>1-е занятие<\/strong>/', $html);
        $this->assertMatchesRegularExpression('/<span class="fs-line fs-past fs-last" style="background:#FDE047;color:#1F2430;padding:0 6px;border-radius:6px;" hidden><strong>2-е занятие<\/strong>/', $html);

        // Будущее занятие видно (с групповым разрывом перед собой).
        $this->assertMatchesRegularExpression('/<span class="fs-line">(<br>\s*)?<strong>3-е занятие<\/strong>: /', $html);
    }

    /** @test */
    public function ongoing_lesson_is_not_past_yet(): void
    {
        $group = Group::factory()->create();

        // Идёт сейчас (старт час назад, длительность 2ч) — ещё НЕ «прошедшее».
        Schedule::create(['title' => 'A', 'start' => now()->subHour()->format('Y-m-d H:i:s'), 'group_id' => $group->id]);
        Schedule::create(['title' => 'B', 'start' => now()->addDays(7)->format('Y-m-d H:i:s'), 'group_id' => $group->id]);

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);
        $this->assertSame(0, $post->pastCount);
        $this->assertNull($post->lastPast);

        $html = $post->html();

        // Ничего не прошло — классическая разметка без статуса, кнопки и скрытия.
        $this->assertStringNotContainsString('fs-status', $html);
        $this->assertStringNotContainsString('fs-toggle', $html);
        $this->assertStringNotContainsString('fs-past', $html);
        $this->assertStringNotContainsString('fs-last', $html);
        $this->assertStringNotContainsString('hidden', $html);
    }

    /** @test */
    public function past_overview_is_highlighted_when_no_lesson_passed_yet(): void
    {
        $group = Group::factory()->create();

        Schedule::create(['title' => 'Обзорное', 'start' => now()->subDays(3)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'is_overview' => true]);
        Schedule::create(['title' => '1', 'start' => now()->addDays(4)->format('Y-m-d H:i:s'), 'group_id' => $group->id]);

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);
        $this->assertSame(0, $post->pastCount);
        $this->assertNotNull($post->lastPast);

        $html = $post->html();

        $this->assertStringContainsString('<p class="fs-status">Последнее прошедшее: ', $html);
        // Обзорное скрыто и подсвечено (строка даты).
        $this->assertMatchesRegularExpression('/class="fs-line fs-past fs-last" style="background:#FDE047[^"]*" hidden>/', $html);
        // 1-е занятие видно.
        $this->assertMatchesRegularExpression('/<span class="fs-line"><strong>1-е занятие<\/strong>/', $html);
    }

    /** @test */
    public function visible_option_keeps_classic_markup_with_highlight(): void
    {
        $group = Group::factory()->create();

        Schedule::create(['title' => 'A', 'start' => now()->subDays(14)->format('Y-m-d H:i:s'), 'group_id' => $group->id]);
        Schedule::create(['title' => 'B', 'start' => now()->addDays(7)->format('Y-m-d H:i:s'), 'group_id' => $group->id]);

        $post = FullSchedulePost::forGroup($group);
        $this->assertNotNull($post);

        $html = $post->html(['past' => 'visible']);

        // Ничего не скрыто, статус/кнопки нет, но прошедшее подсвечено.
        $this->assertStringNotContainsString('hidden', $html);
        $this->assertStringNotContainsString('fs-toggle', $html);
        $this->assertStringNotContainsString('fs-status', $html);
        $this->assertStringContainsString('<span class="fs-last" style="background:#FDE047;color:#1F2430;padding:0 6px;border-radius:6px;"><strong>1-е занятие</strong>: ', $html);
        $this->assertStringContainsString('<strong>2-е занятие</strong>: ', $html);
    }
}
