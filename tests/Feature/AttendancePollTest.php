<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiPollJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Models\TelegramPoll;
use App\Models\TelegramPollAnswer;
use App\Models\User;
use App\Services\Telegram\ZapisiPollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Автоопрос о присутствии перед занятием (classes:post-attendance-poll):
 * один опрос на слот в чат группы, голоса учеников с привязанным Telegram
 * пишутся в предупреждения о занятии (ScheduleAttendanceNotice).
 */
class AttendancePollTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100777';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-16 12:00:00');
        config([
            'features.telegram_zapisi_bot' => true,
            'features.attendance_poll_autopost' => true,
            'features.attendance_poll_lead_minutes' => 180,
            'features.attendance_poll_group_ids' => [],
            'features.attendance_notices' => true,
        ]);
        MarketingSetting::create(['zapisi_bot_username' => 'zapisi_ORSbot', 'zapisi_bot_token' => 'ZAPISI-TOKEN']);
        MarketingSetting::flushCached();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function group(?string $chatId = self::CHAT_ID, string $name = 'Грамматика гр.64'): Group
    {
        return Group::create(['name' => $name, 'telegram_chat_id' => $chatId]);
    }

    private function lesson(Group $group, string $start, string $title = 'Грамматика по Кочергиной'): Schedule
    {
        return Schedule::create([
            'title' => $title,
            'start' => $start,
            'end' => Carbon::parse($start)->addHours(2),
            'group_id' => $group->id,
        ]);
    }

    private function student(Group $group, int $telegramId): User
    {
        $user = User::factory()->create(['telegram_id' => $telegramId]);
        $user->groups()->attach($group->id);

        return $user;
    }

    /** Опрос о присутствии, уже «отправленный» в чат (tg_poll_id известен). */
    private function sentAttendancePoll(Group $group, Schedule $schedule): TelegramPoll
    {
        $poll = app(ZapisiPollService::class)->createAttendancePoll($schedule, $group);
        $poll->update(['status' => TelegramPoll::STATUS_SENT, 'tg_poll_id' => '9990001', 'message_id' => 5]);

        return $poll;
    }

    private function vote(int $telegramId, array $optionIds): void
    {
        app(ZapisiPollService::class)->recordAnswer([
            'poll_id' => '9990001',
            'user' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'Анна'],
            'option_ids' => $optionIds,
        ]);
    }

    public function test_flag_off_posts_nothing(): void
    {
        config(['features.attendance_poll_autopost' => false]);
        $this->lesson($this->group(), '2026-11-16 14:00:00');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertSame(0, TelegramPoll::count());
        Queue::assertNothingPushed();
    }

    public function test_posts_one_poll_per_lesson_and_does_not_repeat(): void
    {
        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 14:00:00');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();
        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertSame(1, TelegramPoll::count());
        $poll = TelegramPoll::firstOrFail();
        $this->assertSame(TelegramPoll::KIND_ATTENDANCE, $poll->kind);
        $this->assertSame($schedule->id, $poll->schedule_id);
        $this->assertSame(self::CHAT_ID, $poll->chat_id);
        $this->assertSame(TelegramPoll::ATTENDANCE_OPTIONS, $poll->options);
        $this->assertFalse($poll->allows_multiple);
        $this->assertStringContainsString('Грамматика по Кочергиной', $poll->question);
        $this->assertSame('Занятие №1 · Грамматика по Кочергиной · сегодня, пн 16.11.2026 в 14:00 (МСК). Будете?', $poll->question);
        Queue::assertPushed(SendZapisiPollJob::class, 1);
    }

    public function test_duplicate_schedule_rows_of_one_slot_get_one_poll(): void
    {
        $group = $this->group();
        $this->lesson($group, '2026-11-16 14:00:00');
        $this->lesson($group, '2026-11-16 14:00:00');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertSame(1, TelegramPoll::count());
    }

    public function test_skips_lessons_outside_the_window_and_groups_without_chat(): void
    {
        $this->lesson($this->group(), '2026-11-16 12:10:00'); // меньше 20 минут до старта
        $this->lesson($this->group('-100778', 'Чтение гр.3'), '2026-11-16 16:30:00'); // дальше 180 минут
        $this->lesson($this->group(null, 'Без чата'), '2026-11-16 14:00:00');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertSame(0, TelegramPoll::count());
    }

    public function test_pilot_group_list_limits_polls_to_listed_groups(): void
    {
        $pilot = $this->group();
        $other = $this->group('-100779', 'Чтение гр.3');
        config(['features.attendance_poll_group_ids' => [$pilot->id]]);
        $this->lesson($pilot, '2026-11-16 14:00:00');
        $this->lesson($other, '2026-11-16 14:00:00');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertSame([$pilot->id], TelegramPoll::pluck('group_id')->all());
    }

    public function test_rescheduled_lesson_gets_a_new_poll(): void
    {
        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 14:00:00');
        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $schedule->update(['start' => '2026-11-16 14:30:00', 'end' => '2026-11-16 16:30:00']);
        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertSame(2, TelegramPoll::count());
    }

    public function test_tomorrow_lesson_question_carries_the_date(): void
    {
        Carbon::setTestNow('2026-11-16 23:00:00');
        $this->lesson($this->group(), '2026-11-17 00:30:00');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertStringContainsString('завтра, вт 17.11.2026 в 00:30 (МСК)', TelegramPoll::firstOrFail()->question);
    }

    public function test_question_takes_lesson_number_from_schedule_title(): void
    {
        $group = $this->group();
        $this->lesson($group, '2026-11-14 11:00:00', 'Грамматика по Кочергиной гр.64 (#1, 14.11.26)');
        $this->lesson($group, '2026-11-16 14:00:00', 'Грамматика по Кочергиной гр.64 (#2, 16.11.26)');
        $other = $this->group('-100778', 'Бюллер гр.27');
        $this->lesson($other, '2026-11-16 14:30:00', 'Грамматика по Бюллеру гр.27 (урок 9, 16.11.26)');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $questions = TelegramPoll::orderBy('id')->pluck('question')->all();
        $this->assertSame([
            'Занятие №2 · Грамматика по Кочергиной гр.64 · сегодня, пн 16.11.2026 в 14:00 (МСК). Будете?',
            'Занятие №9 · Грамматика по Бюллеру гр.27 · сегодня, пн 16.11.2026 в 14:30 (МСК). Будете?',
        ], $questions);
    }

    public function test_question_without_number_in_title_counts_group_lessons(): void
    {
        $group = $this->group();
        $this->lesson($group, '2026-11-09 14:00:00');
        $this->lesson($group, '2026-11-12 14:00:00');
        $this->lesson($group, '2026-11-16 14:00:00');

        $this->artisan('classes:post-attendance-poll')->assertSuccessful();

        $this->assertStringStartsWith('Занятие №3 · Грамматика по Кочергиной · ', TelegramPoll::firstOrFail()->question);
    }

    public function test_votes_of_linked_student_become_attendance_notices(): void
    {
        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 14:00:00');
        $student = $this->student($group, 4242);
        $this->sentAttendancePoll($group, $schedule);

        $this->vote(4242, [TelegramPoll::ATTENDANCE_OPTION_ABSENT]);
        $notice = ScheduleAttendanceNotice::where('user_id', $student->id)->where('schedule_id', $schedule->id)->firstOrFail();
        $this->assertSame(ScheduleAttendanceNotice::STATUS_ABSENT, $notice->status);
        $this->assertSame(ScheduleAttendanceNotice::SOURCE_TELEGRAM_POLL, $notice->source);

        $this->vote(4242, [TelegramPoll::ATTENDANCE_OPTION_LATE]);
        $this->assertSame(ScheduleAttendanceNotice::STATUS_LATE, $notice->fresh()->status);

        // «Буду» снимает предупреждение.
        $this->vote(4242, [TelegramPoll::ATTENDANCE_OPTION_COMING]);
        $this->assertSame(0, ScheduleAttendanceNotice::count());

        // Отозванный голос тоже не оставляет предупреждения.
        $this->vote(4242, [TelegramPoll::ATTENDANCE_OPTION_ABSENT]);
        $this->vote(4242, []);
        $this->assertSame(0, ScheduleAttendanceNotice::count());
    }

    public function test_unlinked_or_foreign_voter_is_recorded_without_notice(): void
    {
        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 14:00:00');
        $this->sentAttendancePoll($group, $schedule);
        // Привязанный Telegram, но ученик не из этой группы.
        User::factory()->create(['telegram_id' => 5151]);

        $this->vote(9999, [TelegramPoll::ATTENDANCE_OPTION_ABSENT]); // Telegram не привязан
        $this->vote(5151, [TelegramPoll::ATTENDANCE_OPTION_ABSENT]);

        $this->assertSame(2, TelegramPollAnswer::count());
        $this->assertSame(0, ScheduleAttendanceNotice::count());
    }

    public function test_vote_after_reschedule_or_with_notices_off_writes_no_notice(): void
    {
        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 14:00:00');
        $this->student($group, 4242);
        $this->sentAttendancePoll($group, $schedule);

        config(['features.attendance_notices' => false]);
        $this->vote(4242, [TelegramPoll::ATTENDANCE_OPTION_ABSENT]);
        $this->assertSame(0, ScheduleAttendanceNotice::count());

        // Занятие перенесли — старый опрос был про другое время.
        config(['features.attendance_notices' => true]);
        $schedule->update(['start' => '2026-11-17 14:00:00', 'end' => '2026-11-17 16:00:00']);
        $this->vote(4242, [TelegramPoll::ATTENDANCE_OPTION_ABSENT]);
        $this->assertSame(0, ScheduleAttendanceNotice::count());
    }

    public function test_manual_poll_votes_never_touch_attendance_notices(): void
    {
        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 14:00:00');
        $this->student($group, 4242);
        TelegramPoll::create([
            'group_id' => $group->id,
            'chat_id' => self::CHAT_ID,
            'question' => 'Переносим на четверг?',
            'options' => ['Да', 'Нет', 'Не знаю'],
            'status' => TelegramPoll::STATUS_SENT,
            'tg_poll_id' => '9990001',
            'message_id' => 6,
        ]);

        $this->vote(4242, [2]);

        $this->assertSame(1, TelegramPollAnswer::count());
        $this->assertSame(0, ScheduleAttendanceNotice::count());
        $this->assertNotNull($schedule->fresh());
    }
}
