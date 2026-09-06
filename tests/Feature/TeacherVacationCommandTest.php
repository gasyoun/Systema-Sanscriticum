<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Telegram\VacationCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4253: TG-команда «Каникулы/отпуск» — преподаватель ставит окно себе,
 * staff (admin/manager) тем же текстом ставит флаг группе чата,
 * неопознанный отправитель молча игнорируется.
 */
class TeacherVacationCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100555';

    private function seedTeacherWorld(): array
    {
        $teacher = Teacher::create(['name' => 'Толчельников Иван Евгеньевич']);
        $course = Course::create(['title' => 'Грамматика по Кочергиной', 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Гр.62', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($group->id);
        $user = User::create([
            'name' => 'Толчельников',
            'email' => 'tolch@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'teacher',
            'teacher_id' => $teacher->id,
            'telegram_id' => 471824146,
        ]);

        return [$teacher, $course, $group, $user];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(string $text, array $overrides = []): array
    {
        return array_merge([
            'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
            'message_id' => 900,
            'from' => ['id' => 471824146],
            'text' => $text,
        ], $overrides);
    }

    public function test_teacher_sets_window_with_confirmation(): void
    {
        Queue::fake();
        [$teacher] = $this->seedTeacherWorld();

        app(VacationCommandService::class)->handle($this->message('Каникулы с 23.09 по 06.10!'));

        $teacher->refresh();
        $this->assertSame(now()->year.'-09-23', $teacher->on_vacation_from->toDateString());
        $this->assertSame(now()->year.'-10-06', $teacher->on_vacation_until->toDateString());

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return $job->chatId === self::CHAT_ID
                && str_contains($job->text, 'Отпуск отмечен')
                && str_contains($job->text, 'с 23.09.');
        });
    }

    public function test_open_ended_vacation_without_until(): void
    {
        Queue::fake();
        [$teacher] = $this->seedTeacherWorld();

        app(VacationCommandService::class)->handle($this->message('отпуск с 01.11'));

        $teacher->refresh();
        $this->assertSame(now()->year.'-11-01', $teacher->on_vacation_from->toDateString());
        $this->assertNull($teacher->on_vacation_until);
    }

    public function test_date_without_year_in_past_resolves_to_next_year(): void
    {
        Queue::fake();
        [$teacher] = $this->seedTeacherWorld();
        $year = now()->year;
        $past = '01.08'; // август почти наверняка в прошлом относительно «сейчас» в тесте

        app(VacationCommandService::class)->handle($this->message('Каникулы с '.$past.' по 15.08'));

        $teacher->refresh();
        $expected = $teacher->on_vacation_from->toDateString();
        $this->assertContains($expected, [$year.'-08-01', ($year + 1).'-08-01']);
        $this->assertNotSame($year.'-08-01', $expected); // дата в прошлом → следующий год
    }

    public function test_clear_resets_window(): void
    {
        Queue::fake();
        [$teacher] = $this->seedTeacherWorld();
        $teacher->forceFill(['on_vacation_from' => '2026-09-23', 'on_vacation_until' => '2026-10-06'])->save();

        app(VacationCommandService::class)->handle($this->message('Вышла из отпуска'));

        $teacher->refresh();
        $this->assertNull($teacher->on_vacation_from);
        $this->assertNull($teacher->on_vacation_until);
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    public function test_unknown_sender_is_silent(): void
    {
        Queue::fake();
        $this->seedTeacherWorld();

        app(VacationCommandService::class)->handle($this->message('Каникулы с 23.09 по 06.10', [
            'from' => ['id' => 1],
        ]));

        Queue::assertNothingPushed();
        $this->assertSame(0, Teacher::whereNotNull('on_vacation_from')->count());
    }

    public function test_admin_in_chat_sets_group_flag(): void
    {
        Queue::fake();
        [, , $group] = $this->seedTeacherWorld();
        User::create([
            'name' => 'Админ',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
            'telegram_id' => 427307131,
        ]);

        app(VacationCommandService::class)->handle($this->message('Каникулы с 23.09 по 06.10', [
            'from' => ['id' => 427307131],
        ]));

        $group->refresh();
        $this->assertTrue($group->is_on_vacation);
        $this->assertSame(now()->year.'-10-06', $group->vacation_resume_date?->toDateString());
        $this->assertSame(0, Teacher::whereNotNull('on_vacation_from')->count());
    }

    public function test_staff_without_group_chat_is_silent(): void
    {
        Queue::fake();
        $this->seedTeacherWorld();
        User::create([
            'name' => 'Менеджер',
            'email' => 'manager@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'manager',
            'telegram_id' => 908572509,
        ]);

        app(VacationCommandService::class)->handle($this->message('Каникулы с 23.09 по 06.10', [
            'chat' => ['id' => '-100999', 'type' => 'supergroup'],
            'from' => ['id' => 908572509],
        ]));

        Queue::assertNothingPushed();
        $this->assertSame(0, Group::where('is_on_vacation', true)->count());
    }

    public function test_plain_text_is_ignored(): void
    {
        Queue::fake();
        $this->seedTeacherWorld();

        app(VacationCommandService::class)->handle($this->message('Добрый день! Каникулы были отличные'));

        Queue::assertNothingPushed();
        $this->assertSame(0, Teacher::whereNotNull('on_vacation_from')->count());
    }
}
