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
 * H6546/H6547: рассылка копий (copyMessage от @zapisi_ORSbot; copies off —
 * вердикт MG 12-10 вечер) + черновик админам прямой отправкой бюджетом очередей
 * с pending-контекстом для reply-обработчика сторис. Идемпотентность на
 * TelegramSendGuard-клеймах: ретрай/повтор не даёт вторую копию и второй черновик.
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
            'video-anons.stories_enabled' => true,
            'video-anons.copies_enabled' => true,
            'video-anons.courses' => [
                'leitana' => ['title' => 'Курс Эдгара Лейтана', 'chat_ids' => [-100111, -100222, -100333]],
            ],
        ]);
    }

    private function job(array $targets = [-100222, -100333], ?string $mediaPath = '/tmp/fake/1067_video.mp4'): DistributeVideoAnonsJob
    {
        return new DistributeVideoAnonsJob(-100111, 1067, 'leitana', $targets, 'video-anons:lane:-100111:1067', $mediaPath);
    }

    public function test_copies_to_all_targets_and_draft_with_pending_context(): void
    {
        // Клейма: 2 копии + черновик; продления: 2 копии + черновик;
        // pending-контекст на доставленный черновик в личке админа.
        Redis::shouldReceive('set')->times(4)->andReturn(true);
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
            && str_contains((string) $request['text'], 'https://t.me/c/111/1067'));
        Redis::shouldHaveReceived('set')->with(
            \Mockery::pattern('~^video-anons:pending:427307131:5002$~'),
            \Mockery::on(fn (string $json): bool => str_contains($json, '/tmp/fake/1067_video.mp4')),
            'EX',
            604800,
        )->once();
    }

    public function test_copies_off_draft_still_sends_with_stories_instructions(): void
    {
        // Вердикт MG 12-10 вечер: копии выключены — джоб идёт с пустыми целями,
        // черновик остаётся приёмкой заголовка сторис.
        config(['video-anons.copies_enabled' => false]);
        Redis::shouldReceive('set')->times(2)->andReturn(true);
        Redis::shouldReceive('expire')->once();

        Http::fake([
            'api.telegram.org/botZAPISI-TOKEN/*' => Http::response(['ok' => true]),
            'api.telegram.org/botMAIN-TOKEN/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5002]]),
        ]);

        $this->job(targets: [])->handle();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'copyMessage'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botMAIN-TOKEN/sendMessage')
            && str_contains((string) $request['text'], 'копии по чатам выключены')
            && str_contains((string) $request['text'], 'ЗАГОЛОВКОМ')
            && str_contains((string) $request['text'], 'готов к сторис'));
    }

    public function test_declined_copy_does_not_block_other_targets_or_draft(): void
    {
        // Первый чат: Telegram отказал (ключ отпущен). Второй: доставлен.
        Redis::shouldReceive('set')->times(4)->andReturn(true);
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

    public function test_draft_failure_throws_and_releases_draft_key_for_retry(): void
    {
        // Прогон 1: копии доставлены (клейма продлены), черновик поймал отказ →
        // джоб бросает, ключ черновика отпущен; ретрай (клейма копий заняты)
        // доставляет только черновик + пишет pending.
        Redis::shouldReceive('set')->times(7)->andReturn(true, true, true, false, false, true, true);
        Redis::shouldReceive('expire')->times(3);
        Redis::shouldReceive('del')->once();

        Http::fake([
            'api.telegram.org/botZAPISI-TOKEN/copyMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5001]]),
            'api.telegram.org/botMAIN-TOKEN/sendMessage' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Internal Server Error: simulated blip'], 500)
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
