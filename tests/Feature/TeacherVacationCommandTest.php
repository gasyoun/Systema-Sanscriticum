<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Telegram\TeacherVacationCommandService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H4253: «Каникулы с ДД.ММ по ДД.ММ» / «отпуск …» / «занятия возобновляются»
 * в чате группы — ставит/снимает teacher-level окно отпуска на всех
 * преподавателей курсов группы. ACL: admin/manager — любая группа; teacher —
 * только своя (User.teacher_id -> Group::ledBy).
 */
class TeacherVacationCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100555';

    private function groupWithTeacher(): array
    {
        $teacher = Teacher::create(['name' => 'Костина Е.А.', 'email' => 'kostina@example.com']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'kurs-vacation', 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа', 'telegram_chat_id' => self::CHAT_ID]);
        $group->courses()->attach($course->id);

        return [$group, $teacher];
    }

    private function admin(int $telegramId): User
    {
        $user = User::create(['name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'x', 'role' => Roles::ADMIN]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => SocialAccount::PROVIDER_TELEGRAM, 'provider_id' => (string) $telegramId]);

        return $user;
    }

    public function test_admin_sets_vacation_window_on_all_group_teachers(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->once()->andReturn(true);
        [$group, $teacher] = $this->groupWithTeacher();
        $this->admin(42);

        app(TeacherVacationCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 42],
            'text' => 'Каникулы с 10.09 по 20.09',
        ]);

        $fresh = $teacher->fresh();
        $this->assertSame((string) now()->year.'-09-10', $fresh->on_vacation_from->toDateString());
        $this->assertSame((string) now()->year.'-09-20', $fresh->on_vacation_until->toDateString());
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === self::CHAT_ID && str_contains($job->text, 'Отпуск оформлен'));
    }

    public function test_resume_phrase_clears_vacation_window(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->once()->andReturn(true);
        [$group, $teacher] = $this->groupWithTeacher();
        $teacher->update(['on_vacation_from' => now()->toDateString(), 'on_vacation_until' => now()->addWeek()->toDateString()]);
        $this->admin(42);

        app(TeacherVacationCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 42],
            'text' => 'занятия возобновляются',
        ]);

        $fresh = $teacher->fresh();
        $this->assertNull($fresh->on_vacation_from);
        $this->assertNull($fresh->on_vacation_until);
    }

    public function test_unknown_telegram_account_is_refused_silently(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();
        [$group, $teacher] = $this->groupWithTeacher();

        app(TeacherVacationCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 999],
            'text' => 'Каникулы с 10.09 по 20.09',
        ]);

        Queue::assertNothingPushed();
        $this->assertNull($teacher->fresh()->on_vacation_from);
    }

    public function test_teacher_cannot_set_vacation_for_a_group_not_their_own(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();
        [$group, $teacher] = $this->groupWithTeacher();

        $otherTeacher = Teacher::create(['name' => 'Другой', 'email' => 'other@example.com']);
        $teacherUser = User::create(['name' => 'Препод', 'email' => 'teacher@example.com', 'password' => 'x', 'role' => Roles::TEACHER, 'teacher_id' => $otherTeacher->id]);
        SocialAccount::create(['user_id' => $teacherUser->id, 'provider' => SocialAccount::PROVIDER_TELEGRAM, 'provider_id' => '77']);

        app(TeacherVacationCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 77],
            'text' => 'Каникулы с 10.09 по 20.09',
        ]);

        Queue::assertNothingPushed();
        $this->assertNull($teacher->fresh()->on_vacation_from);
    }

    public function test_teacher_can_set_vacation_for_their_own_group(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->once()->andReturn(true);
        [$group, $teacher] = $this->groupWithTeacher();

        $teacherUser = User::create(['name' => 'Препод', 'email' => 'teacher2@example.com', 'password' => 'x', 'role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);
        SocialAccount::create(['user_id' => $teacherUser->id, 'provider' => SocialAccount::PROVIDER_TELEGRAM, 'provider_id' => '88']);

        app(TeacherVacationCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 88],
            'text' => 'Каникулы с 10.09 по 20.09',
        ]);

        $this->assertNotNull($teacher->fresh()->on_vacation_from);
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }
}
