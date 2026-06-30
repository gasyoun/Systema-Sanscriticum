<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ClassRoster;
use App\Services\GroupMembershipManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupMembershipSoftLeaveTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): GroupMembershipManager
    {
        return app(GroupMembershipManager::class);
    }

    /** Студента с терминальным статусом курса выводим из активного состава, но доступ сохраняется. */
    public function test_terminal_status_soft_leaves_group_but_keeps_access(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток 1']);
        $course->groups()->attach($group->id);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        $user->courses()->attach($course->id, ['status' => 'Записался']);

        $user->courses()->updateExistingPivot($course->id, ['status' => 'Выпускник']);
        $this->manager()->syncForCourse($user->fresh(), $course);

        $user->refresh();

        // Путь ДОСТУПА: членство осталось → курс по-прежнему виден в ЛК (gate dashboard/showCourse).
        $this->assertTrue($user->groups->pluck('id')->contains($group->id));
        $accessVisible = Course::whereHas('groups', fn ($q) => $q->whereIn('groups.id', $user->groups->pluck('id')))
            ->whereKey($course->id)
            ->exists();
        $this->assertTrue($accessVisible, 'Курс остаётся доступен после выхода из активного состава.');

        // Активный состав: исключён.
        $this->assertFalse($user->activeGroups->pluck('id')->contains($group->id));
        $this->assertFalse($group->activeUsers()->whereKey($user->id)->exists());
    }

    /** ClassRoster (напоминания/посещаемость) показывает только активный состав. */
    public function test_class_roster_excludes_left_students(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток 1']);
        $course->groups()->attach($group->id);

        $active = User::factory()->create();
        $active->groups()->attach($group->id);

        $left = User::factory()->create();
        $left->groups()->attach($group->id);
        $this->manager()->softLeave($left, [$group->id], GroupMembershipManager::REASON_MANUAL);

        $schedule = new Schedule;
        $schedule->group_id = $group->id;

        $rosterIds = ClassRoster::query($schedule)?->pluck('id')->all() ?? [];

        $this->assertContains($active->id, $rosterIds);
        $this->assertNotContains($left->id, $rosterIds);
    }

    /** Возврат статуса (Вернулся) восстанавливает участника в активном составе. */
    public function test_returning_status_restores_membership(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток 1']);
        $course->groups()->attach($group->id);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        $user->courses()->attach($course->id, ['status' => 'Покинул']);

        $this->manager()->syncForCourse($user->fresh(), $course);
        $this->assertFalse($user->fresh()->activeGroups->pluck('id')->contains($group->id));

        $user->courses()->updateExistingPivot($course->id, ['status' => 'Вернулся']);
        $this->manager()->syncForCourse($user->fresh(), $course);

        $this->assertTrue($user->fresh()->activeGroups->pluck('id')->contains($group->id));
    }

    /** Ручное исключение приоритетнее авто-синка: статус-restore его не возвращает. */
    public function test_manual_leave_outranks_status_sync(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток 1']);
        $course->groups()->attach($group->id);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        $user->courses()->attach($course->id, ['status' => 'Записался']); // активный курс

        // Куратор вручную исключает.
        $this->manager()->softLeave($user, [$group->id], GroupMembershipManager::REASON_MANUAL);
        $this->assertFalse($user->fresh()->activeGroups->pluck('id')->contains($group->id));

        // Авто-синк по активному статусу НЕ должен вернуть (ручной приоритетнее).
        $this->manager()->syncForCourse($user->fresh(), $course);
        $this->assertFalse($user->fresh()->activeGroups->pluck('id')->contains($group->id));

        // Ручной возврат — возвращает.
        $this->manager()->restore($user, [$group->id], GroupMembershipManager::REASON_MANUAL);
        $this->assertTrue($user->fresh()->activeGroups->pluck('id')->contains($group->id));
    }

    /** Общая группа двух курсов: терминальный статус по одному при активном другом — состав активен. */
    public function test_shared_group_stays_active_while_any_course_active(): void
    {
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        $group = Group::create(['name' => 'Общий поток']);
        $courseA->groups()->attach($group->id);
        $courseB->groups()->attach($group->id);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        $user->courses()->attach($courseA->id, ['status' => 'Выпускник']);
        $user->courses()->attach($courseB->id, ['status' => 'Записался']);

        $this->manager()->syncAllForUser($user->fresh());

        $this->assertTrue(
            $user->fresh()->activeGroups->pluck('id')->contains($group->id),
            'Пока активен хотя бы один курс группы — состав активен.'
        );
    }
}
