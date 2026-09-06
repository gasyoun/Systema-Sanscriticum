<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Telegram\DatedCancelCommandService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H4253: «Отмена ДД.ММ[ и ДД.ММ…]» в чате группы — отменяет занятия на
 * указанные даты БЕЗ каскадного сдвига +7 дней (в отличие от reply-команды
 * «Отмена занятия», H4199). ACL — TelegramGroupAcl, отдельно от
 * zapisi_cancel_admin_ids whitelist.
 */
class DatedCancelCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100666';

    private function admin(int $telegramId): User
    {
        $user = User::create(['name' => 'Админ', 'email' => 'admin2@example.com', 'password' => 'x', 'role' => Roles::ADMIN]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => SocialAccount::PROVIDER_TELEGRAM, 'provider_id' => (string) $telegramId]);

        return $user;
    }

    public function test_cancels_single_dated_schedule_without_shift(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->once()->andReturn(true);
        $this->admin(11);

        $group = Group::create(['name' => 'Группа', 'telegram_chat_id' => self::CHAT_ID]);
        $target = Schedule::create([
            'title' => 'Занятие 20.09',
            'start' => now()->addDays(14)->setTime(10, 0),
            'group_id' => $group->id,
        ]);
        $untouched = Schedule::create([
            'title' => 'Занятие 27.09',
            'start' => now()->addDays(21)->setTime(10, 0),
            'group_id' => $group->id,
        ]);

        $dateLabel = $target->start->format('d.m');

        app(DatedCancelCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 11],
            'text' => "Отмена {$dateLabel}",
        ]);

        $this->assertSoftDeleted($target);
        $this->assertNull($untouched->fresh()->deleted_at);
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === self::CHAT_ID && str_contains($job->text, 'без переноса'));
    }

    public function test_cancels_multiple_dates_in_one_command(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->twice()->andReturn(true, true);
        $this->admin(12);

        $group = Group::create(['name' => 'Группа', 'telegram_chat_id' => self::CHAT_ID]);
        $first = Schedule::create(['title' => 'A', 'start' => now()->addDays(14)->setTime(10, 0), 'group_id' => $group->id]);
        $second = Schedule::create(['title' => 'B', 'start' => now()->addDays(21)->setTime(10, 0), 'group_id' => $group->id]);

        app(DatedCancelCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 12],
            'text' => 'Отмена '.$first->start->format('d.m').' и '.$second->start->format('d.m'),
        ]);

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        Queue::assertPushed(SendZapisiBotMessageJob::class, 2);
    }

    public function test_unknown_sender_is_refused_silently(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();

        $group = Group::create(['name' => 'Группа', 'telegram_chat_id' => self::CHAT_ID]);
        $schedule = Schedule::create(['title' => 'A', 'start' => now()->addDays(14)->setTime(10, 0), 'group_id' => $group->id]);

        app(DatedCancelCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 999],
            'text' => 'Отмена '.$schedule->start->format('d.m'),
        ]);

        Queue::assertNothingPushed();
        $this->assertNotNull($schedule->fresh());
    }

    public function test_bare_reply_style_cancel_text_is_not_matched_by_this_service(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();
        $this->admin(13);

        $group = Group::create(['name' => 'Группа', 'telegram_chat_id' => self::CHAT_ID]);

        app(DatedCancelCommandService::class)->handle([
            'chat' => ['id' => self::CHAT_ID],
            'from' => ['id' => 13],
            'text' => 'Отмена занятия',
        ]);

        Queue::assertNothingPushed();
    }
}
