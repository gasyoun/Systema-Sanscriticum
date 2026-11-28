<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\TelegramPoll;
use App\Models\User;
use App\Services\Telegram\PollCancelCallbackService;
use App\Services\Telegram\ZapisiPollReporter;
use App\Services\Telegram\ZapisiPollService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Итоги автоопроса о присутствии в личку админам + кнопка отмены занятия при
 * недоборе «буду» (ZapisiPollReporter / PollCancelCallbackService /
 * classes:close-attendance-polls).
 */
class ZapisiPollReporterTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100777';

    private const ADMIN_CHAT_ID = '100500';

    private int $pollSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-16 12:00:00');
        config([
            'features.telegram_zapisi_bot' => true,
            'features.attendance_poll_autopost' => true,
            'features.attendance_poll_report_admins' => true,
            'features.attendance_poll_report_lead_minutes' => 180,
            'features.attendance_poll_cancel_threshold' => 5,
            'features.attendance_notices' => false,
            'services.telegram.bot_token' => 'MAIN-TOKEN',
            'services.telegram.admin_id' => self::ADMIN_CHAT_ID,
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

    private function group(): Group
    {
        return Group::create(['name' => 'Грамматика гр.64', 'telegram_chat_id' => self::CHAT_ID]);
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

    private function sentAttendancePoll(Group $group, Schedule $schedule): TelegramPoll
    {
        $poll = app(ZapisiPollService::class)->createAttendancePoll($schedule, $group);
        $this->pollSeq++;
        $poll->update(['status' => TelegramPoll::STATUS_SENT, 'tg_poll_id' => '999000'.$this->pollSeq, 'message_id' => 5]);

        return $poll;
    }

    private function vote(TelegramPoll $poll, int $telegramId, array $optionIds): void
    {
        app(ZapisiPollService::class)->recordAnswer([
            'poll_id' => $poll->tg_poll_id,
            'user' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'Анна'],
            'option_ids' => $optionIds,
        ]);
    }

    // ---- coming count -------------------------------------------------------

    public function test_coming_count_counts_only_budu_option(): void
    {
        $group = $this->group();
        $poll = $this->sentAttendancePoll($group, $this->lesson($group, '2026-11-16 15:00:00'));

        $this->vote($poll, 1, [TelegramPoll::ATTENDANCE_OPTION_COMING]);
        $this->vote($poll, 2, [TelegramPoll::ATTENDANCE_OPTION_COMING]);
        $this->vote($poll, 3, [TelegramPoll::ATTENDANCE_OPTION_LATE]);
        $this->vote($poll, 4, [TelegramPoll::ATTENDANCE_OPTION_ABSENT]);
        $this->vote($poll, 5, []); // отозван

        $this->assertSame(2, app(ZapisiPollReporter::class)->comingCount($poll));
    }

    // ---- report on close ----------------------------------------------------

    public function test_close_offers_cancel_button_below_threshold(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 15:00:00');
        $poll = $this->sentAttendancePoll($group, $schedule);

        foreach ([1, 2, 3] as $id) {
            $this->vote($poll, $id, [TelegramPoll::ATTENDANCE_OPTION_COMING]);
        }

        app(ZapisiPollService::class)->close($poll);

        Http::assertSent(function (Request $r) use ($schedule): bool {
            if (! str_contains($r->url(), 'botMAIN-TOKEN/sendMessage')) {
                return false;
            }
            $markup = json_decode((string) ($r['reply_markup'] ?? ''), true);

            return str_contains((string) $r['text'], 'Итоги опроса')
                && ($markup['inline_keyboard'][0][0]['callback_data'] ?? null) === 'pcx:'.$schedule->id
                && ($markup['inline_keyboard'][0][1]['callback_data'] ?? null) === 'pck';
        });
    }

    public function test_close_offers_no_button_at_or_above_threshold(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $group = $this->group();
        $poll = $this->sentAttendancePoll($group, $this->lesson($group, '2026-11-16 15:00:00'));

        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->vote($poll, $id, [TelegramPoll::ATTENDANCE_OPTION_COMING]);
        }

        app(ZapisiPollService::class)->close($poll);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'botMAIN-TOKEN/sendMessage')
            && ! array_key_exists('reply_markup', $r->data()));
    }

    public function test_close_manual_poll_does_not_report(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $group = $this->group();
        $poll = app(ZapisiPollService::class)->create($group, 'Переносим?', ['Да', 'Нет'], false);
        $poll->update(['status' => TelegramPoll::STATUS_SENT, 'tg_poll_id' => '9990002', 'message_id' => 6]);

        app(ZapisiPollService::class)->close($poll);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'botMAIN-TOKEN/sendMessage'));
    }

    public function test_close_does_not_report_when_disabled(): void
    {
        config(['features.attendance_poll_report_admins' => false]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $group = $this->group();
        $poll = $this->sentAttendancePoll($group, $this->lesson($group, '2026-11-16 15:00:00'));

        app(ZapisiPollService::class)->close($poll);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'botMAIN-TOKEN/sendMessage'));
    }

    // ---- auto-close command -------------------------------------------------

    public function test_command_closes_due_poll_and_skips_others(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $group = $this->group();
        $due = $this->sentAttendancePoll($group, $this->lesson($group, '2026-11-16 14:00:00'));       // через 2ч — в окне 180м
        $this->sentAttendancePoll($group, $this->lesson($group, '2026-11-18 15:00:00'));                // за окном
        $closed = $this->sentAttendancePoll($group, $this->lesson($group, '2026-11-16 13:00:00'));       // в окне, но уже закрыт
        $closed->update(['status' => TelegramPoll::STATUS_CLOSED]);

        $this->artisan('classes:close-attendance-polls')->assertSuccessful();

        $this->assertSame(TelegramPoll::STATUS_CLOSED, $due->fresh()->status);
        // Только один отчёт админам (due); закрытый и заоконный — не трогали.
        $sent = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'botMAIN-TOKEN/sendMessage'));
        $this->assertCount(1, $sent->values());
    }

    public function test_command_noop_when_flag_off(): void
    {
        config(['features.attendance_poll_report_admins' => false]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $group = $this->group();
        $poll = $this->sentAttendancePoll($group, $this->lesson($group, '2026-11-16 14:00:00'));

        $this->artisan('classes:close-attendance-polls')->assertSuccessful();

        $this->assertSame(TelegramPoll::STATUS_SENT, $poll->fresh()->status);
    }

    // ---- callbacks ----------------------------------------------------------

    public function test_keep_callback_leaves_lesson_and_edits_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $schedule = $this->lesson($this->group(), '2026-11-16 15:00:00');

        $handled = app(PollCancelCallbackService::class)->handle([
            'id' => 'cb-keep',
            'data' => 'pck',
            'message' => ['chat' => ['id' => self::ADMIN_CHAT_ID], 'message_id' => 99],
        ]);

        $this->assertTrue($handled);
        $this->assertNotNull($schedule->fresh());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'botMAIN-TOKEN/editMessageText')
            && str_contains((string) $r['text'], 'оставлено'));
    }

    public function test_cancel_callback_shifts_series_and_posts_notice(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        User::factory()->create(['role' => Roles::ADMIN, 'telegram_id' => 111]);
        $group = $this->group();
        $schedule = $this->lesson($group, '2026-11-16 15:00:00', 'Занятие (#3, 16.11.26)');
        $next = $this->lesson($group, '2026-11-23 15:00:00', 'Занятие (#4, 23.11.26)');

        $handled = app(PollCancelCallbackService::class)->handle([
            'id' => 'cb-cancel',
            'data' => 'pcx:'.$schedule->id,
            'from' => ['id' => 111],
            'message' => ['chat' => ['id' => self::ADMIN_CHAT_ID], 'message_id' => 99],
        ]);

        $this->assertTrue($handled);
        $this->assertSame('2026-11-23 15:00:00', $schedule->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 15:00:00', $next->fresh()->start->format('Y-m-d H:i:s'));

        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === self::CHAT_ID
            && str_contains($job->text, 'Занятие отменено')
            && str_contains($job->text, 'отсутствие кворума'));

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'botMAIN-TOKEN/editMessageText')
            && str_contains((string) $r['text'], 'отменено'));
    }

    public function test_cancel_callback_unauthorized_is_refused(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        User::factory()->create(['role' => Roles::TEACHER, 'telegram_id' => 222]);
        $schedule = $this->lesson($this->group(), '2026-11-16 15:00:00');

        $handled = app(PollCancelCallbackService::class)->handle([
            'id' => 'cb-no',
            'data' => 'pcx:'.$schedule->id,
            'from' => ['id' => 222],
            'message' => ['chat' => ['id' => self::ADMIN_CHAT_ID], 'message_id' => 99],
        ]);

        $this->assertTrue($handled);
        $this->assertNotNull($schedule->fresh());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'botMAIN-TOKEN/answerCallbackQuery')
            && ($r['text'] ?? null) === 'Недостаточно прав.');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'botMAIN-TOKEN/editMessageText'));
    }
}
