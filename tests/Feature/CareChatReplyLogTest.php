<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\MarketingSetting;
use App\Support\CareChatReplyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H4362 — ежедневный «тест-на-30-минут» в чате «Отдел заботы»:
 * care:post постит HTML «Вестником», ответы-reply на пост ловит
 * ProcessTelegramMagnetUpdate → CareChatReplyLog, care:replies их печатает.
 */
class CareChatReplyLogTest extends TestCase
{
    use RefreshDatabase;

    private const CARE = '-1002079934542';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['recording_gap.care_telegram_chat_id' => self::CARE]);
    }

    private function replyUpdate(string $text, string $chatId = self::CARE, bool $withReply = true): array
    {
        $message = [
            'message_id' => 777,
            'date' => 1789000000,
            'chat' => ['id' => (int) $chatId, 'type' => 'supergroup'],
            'from' => ['id' => 42, 'is_bot' => false, 'username' => 'nastya', 'first_name' => 'Настя'],
            'text' => $text,
        ];
        if ($withReply) {
            $message['reply_to_message'] = [
                'message_id' => 52081,
                'from' => ['id' => 8722284265, 'is_bot' => true, 'username' => 'samskrte_bot'],
                'text' => 'Проверка №1',
            ];
        }

        return ['update_id' => 1, 'message' => $message];
    }

    public function test_reply_in_care_chat_is_captured_as_jsonl_row(): void
    {
        $this->assertTrue(CareChatReplyLog::captureFromUpdate($this->replyUpdate('готово')));

        Storage::disk('local')->assertExists(CareChatReplyLog::PATH);
        $rows = CareChatReplyLog::read();
        $this->assertCount(1, $rows);
        $this->assertSame('готово', $rows[0]['text']);
        $this->assertSame(52081, $rows[0]['reply_to_message_id']);
        $this->assertTrue($rows[0]['reply_to_is_bot']);
        $this->assertSame('nastya', $rows[0]['from_username']);
        $this->assertSame(self::CARE, $rows[0]['chat_id']);
    }

    public function test_non_reply_and_foreign_chat_are_ignored(): void
    {
        $this->assertFalse(CareChatReplyLog::captureFromUpdate($this->replyUpdate('готово', self::CARE, false)));
        $this->assertFalse(CareChatReplyLog::captureFromUpdate($this->replyUpdate('готово', '-100999')));
        $this->assertFalse(CareChatReplyLog::captureFromUpdate(['update_id' => 2, 'callback_query' => []]));

        config(['recording_gap.care_telegram_chat_id' => '']);
        $this->assertFalse(CareChatReplyLog::captureFromUpdate($this->replyUpdate('готово')));

        Storage::disk('local')->assertMissing(CareChatReplyLog::PATH);
    }

    public function test_magnet_job_routes_care_reply_into_log_and_stops(): void
    {
        (new ProcessTelegramMagnetUpdate($this->replyUpdate('сломано: кнопка не жмётся')))->handle();

        $rows = CareChatReplyLog::read();
        $this->assertCount(1, $rows);
        $this->assertSame('сломано: кнопка не жмётся', $rows[0]['text']);
    }

    public function test_replies_command_filters_by_since(): void
    {
        Storage::disk('local')->append(CareChatReplyLog::PATH, json_encode([
            'captured_at' => '2026-09-09T06:00:00+00:00', 'text' => 'старый', 'reply_to_message_id' => 1,
        ]));
        Storage::disk('local')->append(CareChatReplyLog::PATH, json_encode([
            'captured_at' => '2026-09-09T08:00:00+00:00', 'text' => 'готово', 'reply_to_message_id' => 2,
        ]));

        $this->artisan('care:replies', ['--since' => '2026-09-09T07:00:00Z'])
            ->expectsOutputToContain('"text":"готово"')
            ->doesntExpectOutputToContain('старый')
            ->assertExitCode(0);
    }

    public function test_care_post_dry_run_prints_chunks_without_sending(): void
    {
        Http::fake();
        $file = tempnam(sys_get_temp_dir(), 'care');
        file_put_contents($file, "<b>Проверка №1</b>\n\nШаг 1. Открыть страницу.");

        $this->artisan('care:post', ['--file' => $file, '--dry-run' => true])
            ->expectsOutputToContain('"dry_run":true')
            ->expectsOutputToContain('Шаг 1. Открыть страницу.')
            ->assertExitCode(0);

        Http::assertNothingSent();
        @unlink($file);
    }

    public function test_care_post_sends_html_to_care_chat_and_prints_message_id(): void
    {
        MarketingSetting::create(['tg_bot_token' => '123:abc']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 52090]], 200),
        ]);
        $file = tempnam(sys_get_temp_dir(), 'care');
        file_put_contents($file, '<b>Проверка №1</b>');

        $this->artisan('care:post', ['--file' => $file])
            ->expectsOutputToContain('"message_id":52090')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bot123:abc/sendMessage')
                && $request['chat_id'] === self::CARE
                && $request['parse_mode'] === 'HTML'
                && $request['disable_web_page_preview'] === true;
        });
        @unlink($file);
    }

    public function test_care_post_fails_closed_without_token(): void
    {
        Http::fake();
        $file = tempnam(sys_get_temp_dir(), 'care');
        file_put_contents($file, 'x');

        $this->artisan('care:post', ['--file' => $file])->assertExitCode(1);
        Http::assertNothingSent();
        @unlink($file);
    }
}
