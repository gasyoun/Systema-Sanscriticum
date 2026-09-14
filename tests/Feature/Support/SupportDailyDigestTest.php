<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\Support\SupportDailyDigest;
use App\Services\Support\SupportOutgoingAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportDailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $yesterday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yesterday = CarbonImmutable::now('Europe/Moscow')->subDay()->startOfDay();

        config([
            'app.timezone' => 'Europe/Moscow',
            'app.url' => 'https://samskrte.ru',
            'features.support_daily_digest' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
    }

    public function test_flag_off_is_noop(): void
    {
        config(['features.support_daily_digest' => false]);
        Http::fake();

        $this->artisan('support:daily-digest', ['--date' => $this->yesterday->toDateString()])
            ->expectsOutputToContain('выключен')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_dry_prints_yesterday_counts_without_sending(): void
    {
        Http::fake();
        $this->seedYesterday();

        $code = Artisan::call('support:daily-digest', [
            '--dry' => true,
            '--date' => $this->yesterday->toDateString(),
        ]);

        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('Обращений: 1', $out);
        $this->assertStringContainsString('Неотвеченных: 0', $out);
        $this->assertStringContainsString('Горбаченко', $out);
        $this->assertStringContainsString('Гасунс: 1', $out);
        $this->assertStringContainsString('telegram-support/telegram-support-analytics', $out);
        Http::assertNothingSent();
    }

    public function test_sends_html_digest_to_admin_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->seedYesterday();

        $this->artisan('support:daily-digest', ['--date' => $this->yesterday->toDateString()])
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            $text = (string) ($request['text'] ?? '');

            return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
                && (string) $request['chat_id'] === '111'
                && (string) $request['parse_mode'] === 'HTML'
                && str_contains($text, 'Сводка поддержки за')
                && str_contains($text, 'Горбаченко')
                && str_contains($text, SupportOutgoingAttribution::APPLE_MARKER.': 1')
                && str_contains($text, 'Гасунс: 1')
                && str_contains($text, 'ИИ отправил: 1')
                && str_contains($text, '/admin/telegram-support/telegram-support-analytics');
        });
    }

    public function test_kernel_schedules_digest_at_eight_ten_moscow(): void
    {
        $event = $this->eventFor('support:daily-digest');

        $this->assertNotNull($event, 'support:daily-digest должен быть в расписании.');
        $this->assertSame('10 8 * * *', $event->expression);
    }

    public function test_analytics_url_is_clustered_filament_path(): void
    {
        $url = app(SupportDailyDigest::class)->analyticsUrl();

        $this->assertStringContainsString('/admin/telegram-support/telegram-support-analytics', $url);
    }

    private function seedYesterday(): void
    {
        $day = $this->yesterday;
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9102,
            'last_message_at' => $day->setTime(12, 0),
        ]);

        SupportDailyRollup::create([
            'channel' => SupportDailyRollup::CHANNEL_TELEGRAM,
            'telegram_support_chat_id' => $chat->id,
            'conversation_date' => $day->toDateString(),
            'first_message_at' => $day->setTime(9, 0),
            'last_message_at' => $day->setTime(12, 0),
            'incoming_count' => 2,
            'outgoing_count' => 3,
            'human_reply_count' => 2,
            'ai_suggested_count' => 0,
            'ai_sent_count' => 1,
            'is_unanswered' => false,
            'unresolved_after_hours' => false,
            'has_new_contact' => true,
        ]);

        $this->outgoing($account->id, $chat->id, 1, $day->setTime(10, 0), [
            'responder_type' => 'human',
            'responder_marker' => SupportOutgoingAttribution::APPLE_MARKER,
            'text' => 'Добрый день '.SupportOutgoingAttribution::APPLE_MARKER,
        ]);
        $this->outgoing($account->id, $chat->id, 2, $day->setTime(11, 0), [
            'responder_type' => 'human',
            'responder_marker' => SupportOutgoingAttribution::GASUNS_MARKER,
            'text' => 'Вот ссылка на занятие',
        ]);
        $this->outgoing($account->id, $chat->id, 3, $day->setTime(12, 0), [
            'responder_type' => 'ai',
            'ai_state' => 'sent',
            'text' => 'Запись урока: https://example.test/r',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function outgoing(int $accountId, int $chatId, int $messageId, CarbonImmutable $sentAt, array $attrs): void
    {
        TelegramSupportMessage::create(array_merge([
            'telegram_support_account_id' => $accountId,
            'telegram_support_chat_id' => $chatId,
            'telegram_chat_id' => 9102,
            'telegram_message_id' => $messageId,
            'direction' => 'outgoing',
            'text' => 'ok',
            'sent_at' => $sentAt,
        ], $attrs));
    }

    private function eventFor(string $needle): ?Event
    {
        $schedule = $this->app->make(Schedule::class);
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
