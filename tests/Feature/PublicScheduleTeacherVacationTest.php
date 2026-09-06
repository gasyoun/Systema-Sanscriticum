<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\PublicScheduleResource;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * H4253: публичный фид помечает группу отпускной и по окну преподавателя,
 * а не только по групповому флагу H3790. Наружу — те же поля
 * (is_on_vacation / vacation_resume_date), потребители фида не меняются.
 */
class PublicScheduleTeacherVacationTest extends TestCase
{
    use RefreshDatabase;

    private function render(Schedule $schedule): array
    {
        return PublicScheduleResource::make($schedule)->toArray(new Request);
    }

    public function test_teacher_window_marks_group_on_vacation_with_resume_date(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа']);
        $course->groups()->attach($group->id);
        $teacher->forceFill([
            'on_vacation_from' => '2026-09-23',
            'on_vacation_until' => '2026-10-06',
        ])->save();
        $schedule = Schedule::create([
            'title' => 'Занятие',
            'start' => '2026-09-24 20:00:00',
            'group_id' => $group->id,
        ])->setRelation('course', $course)->setRelation('group', $group);

        $row = $this->render($schedule);

        $this->assertTrue($row['group']['is_on_vacation']);
        $this->assertSame('2026-10-06', $row['group']['vacation_resume_date']);
    }

    public function test_open_teacher_window_marks_vacation_without_resume_date(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа']);
        $course->groups()->attach($group->id);
        $teacher->forceFill(['on_vacation_from' => '2026-09-23'])->save();
        $schedule = Schedule::create([
            'title' => 'Занятие',
            'start' => '2026-10-01 20:00:00',
            'group_id' => $group->id,
        ])->setRelation('course', $course)->setRelation('group', $group);

        $row = $this->render($schedule);

        $this->assertTrue($row['group']['is_on_vacation']);
        $this->assertNull($row['group']['vacation_resume_date']);
    }

    public function test_outside_window_and_no_group_flag_means_regular(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа']);
        $course->groups()->attach($group->id);
        $teacher->forceFill([
            'on_vacation_from' => '2026-09-23',
            'on_vacation_until' => '2026-10-06',
        ])->save();
        $schedule = Schedule::create([
            'title' => 'Занятие',
            'start' => '2026-11-01 20:00:00',
            'group_id' => $group->id,
        ])->setRelation('course', $course)->setRelation('group', $group);

        $row = $this->render($schedule);

        $this->assertFalse($row['group']['is_on_vacation']);
        $this->assertNull($row['group']['vacation_resume_date']);
    }

    public function test_group_flag_still_wins_as_before(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа']);
        $course->groups()->attach($group->id);
        $group->forceFill(['is_on_vacation' => true, 'vacation_resume_date' => '2026-09-30'])->save();
        $schedule = Schedule::create([
            'title' => 'Занятие',
            'start' => '2026-09-24 20:00:00',
            'group_id' => $group->id,
        ])->setRelation('course', $course)->setRelation('group', $group);

        $row = $this->render($schedule);

        $this->assertTrue($row['group']['is_on_vacation']);
        $this->assertSame('2026-09-30', $row['group']['vacation_resume_date']);
    }
}
