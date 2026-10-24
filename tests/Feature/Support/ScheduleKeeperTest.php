<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Support\ScheduleKeeper\ScheduleKeeperService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hermes W1 роль 23 `schedule-keeper` (H6187): ответ о расписании только из
 * канонической таблицы schedules — с временем наблюдения, переносами/
 * отменами, двойным временем для не-МСК студентов и гейтом «устаревший
 * источник → проверка человеком». Роль OFF по умолчанию; сервис не пишет в
 * расписание. Синтетический золотой случай (H6181-паттерн) — первый тест.
 */
class ScheduleKeeperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.hermes_schedule_keeper' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-11 14:35:00', config('app.timezone')));
    }

    private function studentInGroup(array $groupAttrs = [], array $userAttrs = []): User
    {
        $user = User::factory()->create($userAttrs);
        $group = Group::create(array_merge(['name' => 'Бхагавадгита-1'], $groupAttrs));
        $user->groups()->attach($group->id);

        return $user->fresh();
    }

    private function lesson(Group|int|null $group, string $start, ?string $title = null): Schedule
    {
        return Schedule::create([
            'title' => $title ?? '',
            'group_id' => $group instanceof Group ? $group->id : $group,
            'start' => $start,
        ]);
    }

    /** Прямое управление created_at/updated_at — Eloquent перезатирает их при save(). */
    private function backdateTimestamps(Schedule $lesson, string $createdAt, ?string $updatedAt = null): void
    {
        DB::table('schedules')->where('id', $lesson->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ]);
    }

    public function test_synthetic_golden_case_full_draft(): void
    {
        $user = $this->studentInGroup();
        $group = $user->activeGroups()->first();

        $this->lesson($group, '2026-10-18 11:00:00', 'Занятие 12');
        $this->backdateTimestamps(Schedule::latest('id')->first(), '2026-09-11 10:00:00');
        $this->lesson($group, '2026-10-25 11:00:00', 'Занятие 13');
        $this->backdateTimestamps(Schedule::latest('id')->first(), '2026-09-11 10:00:00');

        $moved = $this->lesson($group, '2026-11-01 11:00:00', 'Занятие 14');
        $this->backdateTimestamps($moved, '2026-09-21 10:00:00', '2026-10-10 09:00:00');

        $cancelled = $this->lesson($group, '2026-11-08 11:00:00', 'Занятие 15');
        $this->backdateTimestamps($cancelled, '2026-09-11 10:00:00');
        $cancelled->delete(); // отмена = soft delete (H3790)

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertTrue($result['ok']);
        $this->assertSame('schedule-keeper', $result['role']);
        $this->assertSame(23, $result['role_id']);
        $this->assertFalse($result['requires_human_verification']);
        $this->assertNull($result['verification_reason']);
        $this->assertSame(0.9, $result['confidence']);
        $this->assertSame('2026-10-11T14:35:00+03:00', $result['facts']['observation_time']);

        $this->assertSame(
            "По расписанию на 11.10.2026 14:35 (МСК):\n"
            ."Ближайшие занятия вашей группы:\n"
            ."· 18.10.2026 11:00 МСК — Занятие 12\n"
            ."· 25.10.2026 11:00 МСК — Занятие 13\n"
            ."· 01.11.2026 11:00 МСК — Занятие 14\n"
            ."Изменения в расписании:\n"
            ."· занятие «Занятие 14» изменилось — сейчас в расписании 01.11.2026 11:00 (МСК)\n"
            .'· занятие «Занятие 15» 08.11.2026 11:00 (МСК) отменено',
            $result['draft'],
        );

        $this->assertSame('cancelled', $result['facts']['changes'][1]['kind']);
        $this->assertSame('moved', $result['facts']['changes'][0]['kind']);
        $this->assertCount(3, $result['facts']['upcoming']);
    }

    public function test_role_off_by_default(): void
    {
        // Возвращаем ровно дефолт из config/features.php (env в тестах не задан).
        $fileDefaults = require config_path('features.php');
        config(['features.hermes_schedule_keeper' => $fileDefaults['hermes_schedule_keeper']]);
        $user = $this->studentInGroup();

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertFalse($result['ok']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertSame(23, $result['role_id']);
        $this->assertArrayNotHasKey('draft', $result);
    }

    public function test_non_msk_student_gets_dual_time(): void
    {
        $user = $this->studentInGroup([], ['timezone' => 'Asia/Yekaterinburg']);
        $group = $user->activeGroups()->first();
        $this->lesson($group, '2026-10-18 11:00:00', 'Занятие 12');

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertStringContainsString('· 18.10.2026 11:00 МСК (13:00 ваше) — Занятие 12', $result['draft']);
        $this->assertSame('Asia/Yekaterinburg', $result['facts']['user_timezone']);
        $this->assertSame('13:00', $result['facts']['upcoming'][0]['starts_at_local']['time']);
    }

    public function test_dual_time_day_rollover_shows_local_date(): void
    {
        $user = $this->studentInGroup([], ['timezone' => 'Asia/Vladivostok']);
        $group = $user->activeGroups()->first();
        $this->lesson($group, '2026-10-18 23:30:00');

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        // 23:30 МСК 18.10 = 06:30 19.10 во Владивостоке — дата обязана поехать с временем.
        $this->assertStringContainsString('· 18.10.2026 23:30 МСК (06:30 ваше, 19.10)', $result['draft']);
    }

    public function test_invalid_timezone_falls_back_to_msk_only(): void
    {
        $user = $this->studentInGroup([], ['timezone' => 'Mars/Olympus_Mons']);
        $group = $user->activeGroups()->first();
        $this->lesson($group, '2026-10-18 11:00:00');

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertStringContainsString('· 18.10.2026 11:00 МСК', $result['draft']);
        // «вашей группы» в тексте есть всегда, а вот суффикса дуал-времени быть не должно.
        $this->assertStringNotContainsString('ваше)', $result['draft']);
        $this->assertNull($result['facts']['user_timezone']);
    }

    public function test_no_upcoming_lessons_requires_human(): void
    {
        $user = $this->studentInGroup();

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['requires_human_verification']);
        $this->assertSame('no_upcoming_lessons', $result['verification_reason']);
        $this->assertStringContainsString('ближайших занятий в расписании вашей группы сейчас нет', $result['draft']);
    }

    public function test_student_without_groups_requires_human(): void
    {
        $user = User::factory()->create();

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertTrue($result['requires_human_verification']);
        $this->assertSame('no_upcoming_lessons', $result['verification_reason']);
    }

    public function test_vacation_with_resume_date_is_reported_but_human_gated(): void
    {
        $user = $this->studentInGroup([
            'is_on_vacation' => true,
            'vacation_resume_date' => '2026-11-01',
        ]);

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertTrue($result['requires_human_verification']);
        $this->assertSame('vacation_schedule_pending', $result['verification_reason']);
        $this->assertStringContainsString('Группа «Бхагавадгита-1»: каникулы, возобновление — 01.11.2026', $result['draft']);
    }

    public function test_vacation_without_resume_date_requires_human(): void
    {
        $user = $this->studentInGroup(['is_on_vacation' => true]);

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertSame('vacation_unknown_resume', $result['verification_reason']);
        $this->assertStringContainsString('дата возобновления пока не назначена', $result['draft']);
    }

    public function test_next_lesson_beyond_horizon_requires_human_without_vacation(): void
    {
        $user = $this->studentInGroup();
        $group = $user->activeGroups()->first();
        $this->lesson($group, '2026-12-20 11:00:00'); // +70 дней
        $this->backdateTimestamps(Schedule::latest('id')->first(), '2026-09-11 10:00:00');

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertTrue($result['requires_human_verification']);
        $this->assertSame('next_beyond_horizon', $result['verification_reason']);
    }

    public function test_far_lesson_is_fresh_when_group_is_on_vacation(): void
    {
        $user = $this->studentInGroup(['is_on_vacation' => true, 'vacation_resume_date' => '2026-12-15']);
        $group = $user->activeGroups()->first();
        $this->lesson($group, '2026-12-20 11:00:00');
        $this->backdateTimestamps(Schedule::latest('id')->first(), '2026-09-11 10:00:00');

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        // Каникулы объясняют дальнее занятие — источник не «устаревший».
        $this->assertFalse($result['requires_human_verification']);
    }

    public function test_horizon_boundary_exactly_45_days_is_fresh(): void
    {
        $user = $this->studentInGroup();
        $group = $user->activeGroups()->first();
        $this->lesson($group, now()->addDays(45)->format('Y-m-d H:i:s'));
        $this->backdateTimestamps(Schedule::latest('id')->first(), '2026-09-11 10:00:00');

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        // Строго «дальше» 45 дней — на самой границе источник ещё свеж.
        $this->assertFalse($result['requires_human_verification']);
    }

    public function test_recently_added_lesson_is_reported_as_added(): void
    {
        $user = $this->studentInGroup();
        $group = $user->activeGroups()->first();
        $this->lesson($group, '2026-10-18 11:00:00', 'Дополнительное занятие');

        $result = $this->app->make(ScheduleKeeperService::class)->answerFor($user);

        $this->assertStringContainsString('добавлено занятие «Дополнительное занятие» — 18.10.2026 11:00 (МСК)', $result['draft']);
        $this->assertSame('added', $result['facts']['changes'][0]['kind']);
    }

    public function test_no_writes_to_canonical_schedule(): void
    {
        $user = $this->studentInGroup([], ['timezone' => 'Asia/Yekaterinburg']);
        $group = $user->activeGroups()->first();
        $this->lesson($group, '2026-10-18 11:00:00', 'Занятие 12');
        $this->lesson($group, '2026-10-25 11:00:00', 'Занятие 13');

        $before = [
            'schedules' => DB::table('schedules')->count(),
            'schedule_updates' => DB::table('schedules')->max('updated_at'),
            'groups' => DB::table('groups')->count(),
            'memberships' => DB::table('group_user')->count(),
            'users' => DB::table('users')->count(),
        ];

        $service = $this->app->make(ScheduleKeeperService::class);
        $service->answerFor($user);
        $service->answerFor($user);

        $this->assertSame($before['schedules'], DB::table('schedules')->count());
        $this->assertSame($before['schedule_updates'], DB::table('schedules')->max('updated_at'));
        $this->assertSame($before['groups'], DB::table('groups')->count());
        $this->assertSame($before['memberships'], DB::table('group_user')->count());
        $this->assertSame($before['users'], DB::table('users')->count());
    }
}
