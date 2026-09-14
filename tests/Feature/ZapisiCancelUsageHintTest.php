<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\Telegram\CancelUsageHint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * MG 08-09: преподаватели не знают грамматику команд отмены. «Отменяю завтра»
 * и подобные попытки распознанного sender'а получают подсказку формата —
 * один раз в сутки на чат; студенты и незнакомцы — молча.
 */
class ZapisiCancelUsageHintTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100123';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::forget('marketing_setting.singleton');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return array_merge([
            'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
            'message_id' => 911,
            'from' => ['id' => 111],
            'text' => 'Отменяю завтра',
        ], $overrides);
    }

    public function test_hint_sent_for_unparseable_attempt_by_whitelisted(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        MarketingSetting::create(['zapisi_cancel_admin_ids' => '111']);
        Cache::forget('marketing_setting.singleton');

        CancelUsageHint::maybeSendFor($this->message(['text' => 'Отменяю завтра']));

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return $job->chatId === self::CHAT_ID
                && str_contains($job->text, 'Отмена занятия')
                && str_contains($job->text, 'Отмена 08.09')
                && str_contains($job->text, 'гос. каникулы');
        });
    }

    public function test_no_hint_when_dated_command_recognized(): void
    {
        Redis::shouldReceive('set')->never();
        MarketingSetting::create(['zapisi_cancel_admin_ids' => '111']);
        Cache::forget('marketing_setting.singleton');

        CancelUsageHint::maybeSendFor($this->message([
            'text' => 'Отмена 08.09',
            'reply_to_message' => ['message_id' => 777],
        ]));

        Queue::assertNothingPushed();
    }

    public function test_silent_for_unrecognized_sender(): void
    {
        Redis::shouldReceive('set')->never();

        CancelUsageHint::maybeSendFor($this->message([
            'from' => ['id' => 999],
            'text' => 'Отменяю завтра',
        ]));

        Queue::assertNothingPushed();
    }

    public function test_hint_reaches_acl_panel_user(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        User::create([
            'name' => 'Менеджер',
            'email' => 'mgr@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'manager',
            'telegram_id' => 2002,
        ]);

        CancelUsageHint::maybeSendFor($this->message([
            'from' => ['id' => 2002],
            'text' => 'Отмена',
        ]));

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    public function test_hint_only_once_per_day_per_chat(): void
    {
        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        MarketingSetting::create(['zapisi_cancel_admin_ids' => '111']);
        Cache::forget('marketing_setting.singleton');

        CancelUsageHint::maybeSendFor($this->message(['text' => 'Отменяю завтра']));
        CancelUsageHint::maybeSendFor($this->message(['text' => 'Отмена занятий не будет']));

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }
}
