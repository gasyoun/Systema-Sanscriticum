<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DistributeVideoAnonsJob;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H6546: рассылка копий (copyMessage от @zapisi_ORSbot) + черновик админам.
 * Идемпотентность на TelegramSendGuard-клеймах: ретрай/повтор не даёт вторую
 * копию в чате и второй черновик.
 */
class DistributeVideoAnonsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
        Cache::forget('marketing_setting.singleton');
        config([
            'services.telegram.bot_token' => 'MAIN-TOKEN',
            'services.telegram.admin_id' => '427307131',
            'video-anons.stories_enabled' => false,
            'video-anons.courses' => [
                'leitana' => ['title' => 'Курс Эдгара Лейтана', 'chat_ids' => [-100111, -100222, -100333]],
            ],
        ]);
    }

    private function job(): DistributeVideoAnonsJob
    {
        return new DistributeVideoAnonsJob(-100111, 1067, 'leitana', [-100222, -100333], 'video-anons:lane:-100111:1067');
    }

    public function test_copies_to_all_targets_and_draft_to_admins(): void
    {
        // 2 клейма копий + 1 клейм черновика, все успешны → 3 продления.
        Redis::shouldReceive('set')->times(3)->andReturn(true);
        Redis::shouldReceive('expire')->times(3);

        Http::fake([
            'api.telegram.org/botZAPISI-TOKEN/copyMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5001]]),
            'api.telegram.org/botMAIN-TOKEN/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5002]]),
        ]);

        $this->job()->handle();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'copyMessage')
            && $request['chat_id'] === -100222
            && $request['from_chat_id'] === -100111
            && $request['message_id'] === 1067);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'copyMessage')
            && $request['chat_id'] === -100333);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botMAIN-TOKEN/sendMessage')
            && str_contains((string) $request['text'], 'Доставлено: 2/2')
            && str_contains((string) $request['text'], 'https://t.me/c/111/1067')
            && str_contains((string) $request['text'], 'флаг off'));
    }

    public function test_declined_copy_does_not_block_other_targets_or_draft(): void
    {
        // Первый чат: Telegram отказал (ключ отпущен). Второй: доставлен.
        Redis::shouldReceive('set')->times(3)->andReturn(true);
        Redis::shouldReceive('expire')->times(2);
        Redis::shouldReceive('del')->once();

        Http::fake([
            'api.telegram.org/botZAPISI-TOKEN/copyMessage' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)
                ->push(['ok' => true, 'result' => ['message_id' => 5001]]),
            'api.telegram.org/botMAIN-TOKEN/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5002]]),
        ]);

        $this->job()->handle();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botMAIN-TOKEN/sendMessage')
            && str_contains((string) $request['text'], 'Доставлено: 1/2')
            && str_contains((string) $request['text'], 'НЕ доставлено'));
    }

    public function test_replayed_job_does_not_resend_copies(): void
    {
        // Все клейма заняты (рассылка уже прошла) → ни одного HTTP-вызова.
        Redis::shouldReceive('set')->times(3)->andReturn(false);

        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->job()->handle();

        Http::assertSentCount(0);
    }

    public function test_draft_network_failure_throws_and_releases_draft_key_for_retry(): void
    {
        // Живой случай 12-10-2026: копии доставлены (клейма продлены), черновик
        // поймал DNS-таймаут → джоб бросает, ключ черновика отпущен; ретрай
        // (клейма копий заняты) доставляет только черновик.
        Redis::shouldReceive('set')->times(6)->andReturn(
            true, true,   // прогон 1: два клейма копий
            true,         // прогон 1: клейм черновика
            false, false, // прогон 2 (ретрай): клейма копий заняты
            true,         // прогон 2: клейм черновика заново
        );
        Redis::shouldReceive('expire')->times(3); // прогон 1: две копии; прогон 2: черновик
        Redis::shouldReceive('del')->once();      // прогон 1: отпуск клейма черновика

        Http::fake([
            'api.telegram.org/botZAPISI-TOKEN/copyMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5001]]),
            'api.telegram.org/botMAIN-TOKEN/sendMessage' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Internal Server Error: simulated network blip'], 500)
                ->push(['ok' => true, 'result' => ['message_id' => 5002]]),
        ]);

        $job = $this->job();

        try {
            $job->handle();
            $this->fail('draft failure must throw for retry');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $job->handle();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botMAIN-TOKEN/sendMessage')
            && str_contains((string) $request['text'], 'Доставлено: 2/2'));
    }

    public function test_empty_zapisi_token_skips_distribution(): void
    {
        MarketingSetting::query()->update(['zapisi_bot_token' => null]);
        Cache::forget('marketing_setting.singleton');

        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->job()->handle();

        Http::assertNothingSent();
    }
}
