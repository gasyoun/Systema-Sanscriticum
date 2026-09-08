<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicSchedulePageTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_renders_all_course_schedules_when_flag_on(): void
    {
        config(['features.schedule_full_post' => true]);

        $teacher = Teacher::create(['name' => 'Тестова Мария', 'email' => 't@example.test']);
        $course = Course::factory()->create([
            'title' => 'Введение в индийскую философию', 'slug' => 'fiya',
            'is_active' => true, 'is_visible' => true, 'teacher_id' => $teacher->id,
        ]);
        $hidden = Course::factory()->create(['title' => 'Скрытый курс', 'slug' => 'hidden', 'is_active' => true, 'is_visible' => false]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2027-03-06 11:00'), 'group_id' => $group->id, 'course_id' => $course->id]);
        Schedule::create(['title' => 'B', 'start' => Carbon::parse('2027-03-13 11:00'), 'group_id' => $group->id, 'course_id' => $course->id]);
        Schedule::create(['title' => 'C', 'start' => Carbon::parse('2027-03-20 11:00'), 'group_id' => $group->id, 'course_id' => $hidden->id]);

        $this->get('/raspisanie')
            ->assertOk()
            ->assertSee('Расписание занятий')
            ->assertSee('Введение в индийскую философию')
            ->assertSee('<strong>1-е занятие</strong>: 6 марта 2027 (суббота), 11:00', false)
            ->assertSee('Еженедельно по субботам в 11:00 (по МСК)')
            ->assertSee('/k/fiya')
            ->assertSee('/online/prepodavatel/')
            ->assertDontSee('Скрытый курс');
    }

    /** @test */
    public function flag_off_shows_fallback_without_schedule_blocks(): void
    {
        config(['features.schedule_full_post' => false]);

        $course = Course::factory()->create(['title' => 'Курс X', 'slug' => 'kurs-x', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2027-03-06 11:00'), 'group_id' => $group->id, 'course_id' => $course->id]);

        $this->get('/raspisanie')
            ->assertOk()
            ->assertSee('Расписание курсов скоро появится')
            ->assertDontSee('1-е занятие');
    }

    /** @test */
    public function past_only_courses_are_not_listed(): void
    {
        config(['features.schedule_full_post' => true]);

        $course = Course::factory()->create(['title' => 'Архивный курс', 'slug' => 'arch', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2025-03-07 11:00'), 'group_id' => $group->id]);

        $this->get('/raspisanie')
            ->assertOk()
            ->assertSee('Сейчас нет курсов с предстоящими занятиями')
            ->assertDontSee('Архивный курс');
    }

    /**
     * H4387: у идущего курса прошедшие скрыты, статус и кнопка раскрытия на
     * месте (MG 08-09-2026 — режим «везде одинаково»).
     *
     * @test
     */
    public function running_course_hides_past_with_toggle_and_status(): void
    {
        config(['features.schedule_full_post' => true]);

        $course = Course::factory()->create(['title' => 'Идущий курс', 'slug' => 'running', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        Schedule::create(['title' => 'A', 'start' => now()->subDays(14)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);
        Schedule::create(['title' => 'B', 'start' => now()->addDays(7)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);

        $this->get('/raspisanie')
            ->assertOk()
            ->assertSee('Идущий курс')
            ->assertSee('Прошло занятий: 1 · последнее: ', false)
            ->assertSee('Показать прошедшие занятия')
            ->assertSee('fs-line fs-past', false)
            ->assertSee('fs-last', false)
            ->assertSee('<strong>2-е занятие</strong>: ', false);
    }
}
