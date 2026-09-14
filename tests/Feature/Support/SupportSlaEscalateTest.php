<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Console\Commands\SupportSlaEscalate;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\SupportSlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3999 (рулинг A5): SLA-сеть по открытым тредам без ответа.
 *
 * Три вещи, каждая из которых молча ломает продукт:
 *  1. тихие часы — не «не шлём», а «не копим»: вопрос в 21:55 не должен
 *     разбудить куратора в полночь и не должен к утру «прождать 11 часов»;
 *  2. второй тир не проглатывает первый и не дублирует его;
 *  3. студенту про SLA не уходит НИЧЕГО — это разговор кураторов о своей
 *     очереди, а не обещание «мы скоро ответим».
 */
class SupportSlaEscalateTest extends TestCase
{
    use RefreshDatabase;

    private const CURATOR_ONE = '555001';

    private const CURATOR_TWO = '555002';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_sla_escalation' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'support.sla.curators' => [self::CURATOR_ONE, self::CURATOR_TWO],
            'support.sla.first_ping_minutes' => 15,
            'support.sla.second_ping_minutes' => 60,
            'support.sla.quiet_from' => '22:00',
            'support.sla.quiet_to' => '09:00',
            'support.sla.timezone' => 'Europe/Moscow',
            'support.sla.lookback_hours' => 48,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Открытый тред, последнее сообщение которого — входящее в указанный момент. */
    private function unansweredThread(string $incomingAt, string $direction = 'incoming'): SupportConversation
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $student = User::factory()->create(['name' => 'Студент Тест']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9801],
            ['linked_user_id' => $student->id, 'last_message_at' => $incomingAt],
        );

        $thread = SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
            'channel' => 'telegram',
            'last_message_at' => $incomingAt,
        ]);

        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'support_conversation_id' => $thread->id,
            'telegram_chat_id' => 9801,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => $direction,
            'text' => 'подскажите, когда откроют третий блок?',
            'sent_at' => $incomingAt,
        ]);

        return $thread;
    }

    private function pings(): int
    {
        return SupportAiReplyEvent::query()->where('event_type', SupportSlaEscalate::EVENT)->count();
    }

    public function test_quiet_hours_ping_nobody(): void
    {
        Carbon::setTestNow('2026-09-07 23:30:00');
        $this->unansweredThread('2026-09-07 21:00:00');

        $this->artisan('support:sla-escalate')
            ->expectsOutput('Тихие часы — пингов нет.')
            ->assertExitCode(0);

        $this->assertSame(0, $this->pings());
        Http::assertNothingSent();
    }

    public function test_sixteen_working_minutes_ping_the_first_curator_only(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');
        $this->unansweredThread('2026-09-07 10:44:00');

        $this->artisan('support:sla-escalate')->assertExitCode(0);

        $this->assertSame(1, $this->pings());

        $event = SupportAiReplyEvent::query()->where('event_type', SupportSlaEscalate::EVENT)->firstOrFail();
        $this->assertSame(1, $event->meta['tier']);
        $this->assertSame(self::CURATOR_ONE, $event->meta['curator_chat_id']);

        Http::assertSent(fn ($request) => str_contains((string) ($request['chat_id'] ?? ''), self::CURATOR_ONE)
            && str_contains((string) ($request['text'] ?? ''), 'Вопрос без ответа'));
    }

    public function test_a_second_run_in_the_same_tier_does_not_ping_twice(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');
        $this->unansweredThread('2026-09-07 10:44:00');

        $this->artisan('support:sla-escalate')->assertExitCode(0);

        Carbon::setTestNow('2026-09-07 11:05:00');
        $this->artisan('support:sla-escalate')->assertExitCode(0);

        $this->assertSame(1, $this->pings());
    }

    public function test_the_hour_mark_adds_the_second_curator_without_repeating_the_first(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');
        $this->unansweredThread('2026-09-07 10:44:00');

        $this->artisan('support:sla-escalate')->assertExitCode(0);

        Carbon::setTestNow('2026-09-07 11:45:00');
        $this->artisan('support:sla-escalate')->assertExitCode(0);

        $this->assertSame(2, $this->pings());

        $tiers = SupportAiReplyEvent::query()
            ->where('event_type', SupportSlaEscalate::EVENT)
            ->get()
            ->map(fn ($event): int => (int) $event->meta['tier'])
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2], $tiers);

        $second = SupportAiReplyEvent::query()
            ->where('event_type', SupportSlaEscalate::EVENT)
            ->get()
            ->firstWhere(fn ($event): bool => (int) $event->meta['tier'] === 2);

        $this->assertSame(self::CURATOR_TWO, $second->meta['curator_chat_id']);
    }

    public function test_an_answered_thread_is_not_escalated(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');
        $this->unansweredThread('2026-09-07 09:30:00', 'outgoing');

        $this->artisan('support:sla-escalate')->assertExitCode(0);

        $this->assertSame(0, $this->pings());
        Http::assertNothingSent();
    }

    public function test_an_empty_curator_list_refuses_out_loud(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');
        config(['support.sla.curators' => []]);
        $this->unansweredThread('2026-09-07 10:00:00');

        $this->artisan('support:sla-escalate')
            ->expectsOutputToContain('support.sla.curators пуст')
            ->assertExitCode(0);

        $this->assertSame(0, $this->pings());
    }

    public function test_the_flag_off_is_a_noop_but_dry_still_reports(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');
        config(['features.support_sla_escalation' => false]);
        $this->unansweredThread('2026-09-07 10:00:00');

        $this->artisan('support:sla-escalate')->assertExitCode(0);
        $this->assertSame(0, $this->pings());
        Http::assertNothingSent();

        $this->artisan('support:sla-escalate', ['--dry' => true])
            ->expectsOutputToContain('--dry: ничего не отправлено.')
            ->assertExitCode(0);

        $this->assertSame(0, $this->pings());
        Http::assertNothingSent();
    }

    public function test_the_student_is_never_told_about_the_sla(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');
        $this->unansweredThread('2026-09-07 10:00:00');

        $this->artisan('support:sla-escalate')->assertExitCode(0);

        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'SLA — разговор кураторов о своей очереди; студенту не уходит ничего.',
        );
    }

    /**
     * Тихие часы паузят НАКОПЛЕНИЕ, а не только отправку: вопрос в 21:55 к
     * 09:10 следующего утра прождал 15 рабочих минут, а не одиннадцать часов.
     */
    public function test_the_clock_pauses_overnight_instead_of_counting_the_night(): void
    {
        $clock = new SupportSlaClock('22:00', '09:00', 'Europe/Moscow');

        $evening = CarbonImmutable::parse('2026-09-07 21:55:00', 'Europe/Moscow');

        $this->assertSame(5, $clock->workingMinutesBetween(
            $evening,
            CarbonImmutable::parse('2026-09-08 00:30:00', 'Europe/Moscow'),
        ));

        $this->assertSame(15, $clock->workingMinutesBetween(
            $evening,
            CarbonImmutable::parse('2026-09-08 09:10:00', 'Europe/Moscow'),
        ));

        $this->assertTrue($clock->isQuietNow(CarbonImmutable::parse('2026-09-08 07:00:00', 'Europe/Moscow')));
        $this->assertFalse($clock->isQuietNow(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/Moscow')));
    }

    public function test_a_question_left_overnight_waits_for_the_morning_window(): void
    {
        // Вопрос в 21:55, прогон в 09:05 утра: рабочих минут 5 — порога нет.
        Carbon::setTestNow('2026-09-08 09:05:00');
        $this->unansweredThread('2026-09-07 21:55:00');

        $this->artisan('support:sla-escalate')->assertExitCode(0);
        $this->assertSame(0, $this->pings());

        // 09:20 — 20 рабочих минут, первый тир.
        Carbon::setTestNow('2026-09-08 09:20:00');
        $this->artisan('support:sla-escalate')->assertExitCode(0);
        $this->assertSame(1, $this->pings());
    }
}
