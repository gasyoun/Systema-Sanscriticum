<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\TelegramScheduleReschedule;
use App\Models\User;
use App\Services\Schedule\ScheduleMover;
use App\Services\Telegram\AnnounceRescheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AnnounceRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-1003994169966';

    private const TEACHER_TG_ID = 5487293147;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]]),
        ]);
        config()->set('services.telegram_zapisi.announce_reschedule_detect', true);
        config()->set('services.telegram.student_bot_token', 'test-token');
        Carbon::setTestNow(Carbon::parse('2026-10-27 08:24:54', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedTeacherWorld(bool $socialIdentity = false): Group
    {
        $teacher = Teacher::create(['name' => 'Препод Свой']);
        $course = Course::create([
            'title' => 'Грамматика по Кочергиной гр.63',
            'slug' => 'grammar-63',
            'teacher_id' => $teacher->id,
        ]);
        $group = Group::create(['name' => 'Грамматика по Кочергиной гр.63', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($group->id);
        $user = User::create([
            'name' => 'Препод',
            'email' => 'own@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'teacher',
            'teacher_id' => $teacher->id,
            'telegram_id' => $socialIdentity ? null : self::TEACHER_TG_ID,
        ]);
        if ($socialIdentity) {
            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => SocialAccount::PROVIDER_TELEGRAM,
                'provider_id' => (string) self::TEACHER_TG_ID,
            ]);
        }
        config()->set('services.telegram_zapisi.announce_reschedule_group_ids', [$group->id]);

        return $group;
    }

    private function lesson(Group $group, string $start, string $title): Schedule
    {
        $start = Carbon::parse($start, 'Europe/Moscow');

        return Schedule::create([
            'title' => $title,
            'start' => $start,
            'end' => $start->copy()->addHours(2),
            'group_id' => $group->id,
            'link' => 'https://zoom.us/j/x',
        ]);
    }

    private function goldenLessons(Group $group): array
    {
        return [
            $this->lesson($group, '2026-10-27 08:00:00', 'Грамматика по Кочергиной гр.63 (#2, 27.10.26)'),
            $this->lesson($group, '2026-11-03 08:00:00', 'Грамматика по Кочергиной гр.63 (#3, 03.11.26)'),
        ];
    }

    private function message(string $text, ?int $fromId = null, int $messageId = 93, ?int $date = null): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'message_id' => $messageId,
                'from' => ['id' => $fromId ?? self::TEACHER_TG_ID],
                'text' => $text,
                'date' => $date ?? Carbon::parse('2026-10-27 08:24:54', 'Europe/Moscow')->timestamp,
            ],
        ];
    }

    private function process(array $update): void
    {
        (new ProcessTelegramZapisiUpdate($update))->handle();
    }

    private function goldenText(): string
    {
        return 'Сегодня занятие не состоялось. У нас вынужденный перенос на пятницу 08.00. Было принято решение о разовом переносе на пятницу.';
    }

    public function test_message_93_moves_one_class_and_posts_reply_receipt(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source, $neighbor] = $this->goldenLessons($group);

        $this->process($this->message($this->goldenText()));

        $source->refresh();
        $this->assertSame('2026-10-30 08:00:00', $source->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 10:00:00', $source->end->format('Y-m-d H:i:s'));
        $this->assertSame('Грамматика по Кочергиной гр.63 (#2, 30.10.26)', $source->title);
        $this->assertSame('2026-11-03 08:00:00', $neighbor->fresh()->start->format('Y-m-d H:i:s'));

        $action = TelegramScheduleReschedule::query()->sole();
        $this->assertSame('applied', $action->status);
        $this->assertSame($source->id, $action->schedule_id);
        $this->assertSame('2026-10-27 08:00:00', $action->old_start->format('Y-m-d H:i:s'));
        $this->assertSame(777, $action->receipt_message_id);
        $this->assertTrue($action->undo_expires_at->eq(now()->addHour()));

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/sendMessage')
                && $request['reply_to_message_id'] === 93
                && str_contains((string) $request['text'], '27.10.2026 08:00 → 30.10.2026 08:00')
                && str_contains((string) $request['reply_markup'], 'arsu:');
        });
        Http::assertSentCount(1);
    }

    public function test_two_date_command_preserves_time_when_no_new_time_is_given(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);

        $this->process($this->message('Занятие 27.10 переносим на 30.10'));

        $this->assertSame('2026-10-30 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
    }

    public function test_explicit_new_time_overrides_the_old_time(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);

        $this->process($this->message('Занятие 27.10 переносим на 30.10 в 12:30'));

        $this->assertSame('2026-10-30 12:30:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 14:30:00', $source->fresh()->end->format('Y-m-d H:i:s'));
    }

    public function test_social_account_identity_is_accepted(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld(socialIdentity: true);
        [$source] = $this->goldenLessons($group);

        $this->process($this->message($this->goldenText()));

        $this->assertSame('2026-10-30 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
    }

    public function test_stranger_message_is_ignored(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);

        $this->process($this->message($this->goldenText(), fromId: 9999));

        $this->assertSame('2026-10-27 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
        $this->assertSame(0, TelegramScheduleReschedule::count());
    }

    public function test_flag_off_is_silent(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        config()->set('services.telegram_zapisi.announce_reschedule_detect', false);
        [$source] = $this->goldenLessons($group);

        $this->process($this->message($this->goldenText()));

        $this->assertSame('2026-10-27 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
    }

    public function test_reschedule_flag_off_preserves_existing_cancellation_offer(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        config()->set('services.telegram_zapisi.announce_reschedule_detect', false);
        config()->set('services.telegram_zapisi.announce_cancel_detect', true);
        $source = $this->lesson($group, '2026-10-27 09:00:00', 'Будущее (#2, 27.10.26)');

        $this->process($this->message('Занятие 27.10 переносим на 30.10 в 08:00'));

        $this->assertSame('2026-10-27 09:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Снять с расписания'));
    }

    public function test_group_allowlist_is_fail_closed(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        config()->set('services.telegram_zapisi.announce_reschedule_group_ids', []);
        [$source] = $this->goldenLessons($group);

        $this->process($this->message($this->goldenText()));

        $this->assertSame('2026-10-27 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
    }

    public function test_missing_source_gets_clarification_without_mutation(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);

        $this->process($this->message('У нас разовый перенос занятия на пятницу 08.00'));

        $this->assertSame('2026-10-27 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Подтвердите в формате'));
        $this->assertSame(0, TelegramScheduleReschedule::count());
    }

    public function test_multiple_classes_on_source_day_require_confirmation(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source, $neighbor] = $this->goldenLessons($group);
        $this->lesson($group, '2026-10-27 10:00:00', 'Второе занятие');

        $this->process($this->message($this->goldenText()));

        $this->assertSame('2026-10-27 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 08:00:00', $neighbor->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Подтвердите в формате'));
    }

    public function test_older_past_source_is_not_moved(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $source = $this->lesson($group, '2026-10-26 08:00:00', 'Старое (#1, 26.10.26)');

        $this->process($this->message('Занятие 26.10 переносим на 30.10'));

        $this->assertSame('2026-10-26 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(0, TelegramScheduleReschedule::count());
    }

    public function test_occupied_target_slot_changes_nothing(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);
        $occupied = $this->lesson($group, '2026-10-30 08:00:00', 'Уже стоит');

        $this->process($this->message($this->goldenText()));

        $this->assertSame('2026-10-27 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 08:00:00', $occupied->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(0, TelegramScheduleReschedule::count());
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Целевой слот уже занят'));
    }

    public function test_duplicate_message_id_does_not_move_or_send_twice(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);
        $message = $this->message($this->goldenText())['message'];

        app(AnnounceRescheduleService::class)->handle($message);
        app(AnnounceRescheduleService::class)->handle($message);

        $this->assertSame(1, TelegramScheduleReschedule::count());
        $this->assertSame('2026-10-30 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
    }

    public function test_reschedule_intent_does_not_trigger_cancellation_offer(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        config()->set('services.telegram_zapisi.announce_cancel_detect', true);
        $group = $this->seedTeacherWorld();
        $source = $this->lesson($group, '2026-10-29 08:00:00', 'Будущее (#2, 29.10.26)');

        $this->process($this->message(
            'Занятие 29.10 переносим на 30.10 в 08:00',
            date: Carbon::parse('2026-10-29 07:00:00', 'Europe/Moscow')->timestamp,
        ));

        $this->assertSame('2026-10-30 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ! str_contains((string) $request['text'], 'Снять с расписания'));
    }

    public function test_undo_restores_old_time_and_title(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);
        $this->process($this->message($this->goldenText()));
        $action = TelegramScheduleReschedule::query()->sole();

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'undo-1',
                'from' => ['id' => self::TEACHER_TG_ID],
                'data' => 'arsu:'.$action->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 777],
            ],
        ]);

        $source->refresh();
        $this->assertSame('2026-10-27 08:00:00', $source->start->format('Y-m-d H:i:s'));
        $this->assertSame('Грамматика по Кочергиной гр.63 (#2, 27.10.26)', $source->title);
        $this->assertSame('undone', $action->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], 'Перенос отменён'));
    }

    public function test_expired_undo_does_not_revert(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);
        $this->process($this->message($this->goldenText()));
        $action = TelegramScheduleReschedule::query()->sole();
        Carbon::setTestNow(now()->addHours(2));

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'undo-expired',
                'from' => ['id' => self::TEACHER_TG_ID],
                'data' => 'arsu:'.$action->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 777],
            ],
        ]);

        $this->assertSame('2026-10-30 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('applied', $action->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Час на отмену переноса истёк'));
    }

    public function test_stranger_cannot_undo(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);
        $this->process($this->message($this->goldenText()));
        $action = TelegramScheduleReschedule::query()->sole();

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'undo-stranger',
                'from' => ['id' => 9999],
                'data' => 'arsu:'.$action->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 777],
            ],
        ]);

        $this->assertSame('2026-10-30 08:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('applied', $action->fresh()->status);
    }

    public function test_undo_refuses_to_overwrite_a_later_schedule_change(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        [$source] = $this->goldenLessons($group);
        $this->process($this->message($this->goldenText()));
        $action = TelegramScheduleReschedule::query()->sole();
        app(ScheduleMover::class)->reschedule($source->fresh(), Carbon::parse('2026-11-01 09:00', 'Europe/Moscow'));

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'undo-stale',
                'from' => ['id' => self::TEACHER_TG_ID],
                'data' => 'arsu:'.$action->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 777],
            ],
        ]);

        $this->assertSame('2026-11-01 09:00:00', $source->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('applied', $action->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Расписание уже изменено снова'));
    }
}
