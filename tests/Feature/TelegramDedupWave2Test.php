<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\TelegramZapisiWebhookController;
use App\Jobs\ForwardUpdateToN8n;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\SendTelegramMessageJob;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\HomeworkTelegramTagService;
use App\Services\Messaging\TelegramDeliveryChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Волна 2 антидубль-гварда: личные ЛС, канал доставки, #ДЗ-ответы,
 * ределивери вебхука и повторный приём апдейта поллером.
 */
class TelegramDedupWave2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
        Cache::forget('marketing_setting.singleton');
    }

    // --- SendTelegramMessageJob: чеки/доступы студенту ---

    public function test_personal_dm_retry_is_suppressed_no_second_copy(): void
    {
        $user = User::create([
            'name' => 'Студент',
            'email' => 'dedup-wave2@example.test',
            'password' => bcrypt('secret'),
            'telegram_id' => 424242,
        ]);

        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $job = new SendTelegramMessageJob($user->id, 'Ваш платёж получен');

        $job->handle();
        $job->handle();

        Http::assertSentCount(1);
    }

    // --- TelegramDeliveryChannel: лиды и лист ожидания ---

    public function test_delivery_channel_retry_is_suppressed(): void
    {
        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $channel = (new TelegramDeliveryChannel)->usingCredentials('LEAD-TOKEN', '@leadbot');

        $channel->sendMessage('9001', 'Статус вашей заявки обновлён');
        $channel->sendMessage('9001', 'Статус вашей заявки обновлён');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botLEAD-TOKEN/sendMessage'));
    }

    // --- HomeworkTelegramTagService::sendHtml: ответы на #ДЗ в чате группы ---

    public function test_homework_tag_reply_retry_is_suppressed(): void
    {
        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 10]])]);

        $service = app(HomeworkTelegramTagService::class);
        $method = new \ReflectionMethod($service, 'sendHtml');
        $method->setAccessible(true);

        $method->invoke($service, '-100777', '<b>Домашка принята</b>');
        $method->invoke($service, '-100777', '<b>Домашка принята</b>');

        Http::assertSentCount(1);
    }

    // --- ProcessTelegramZapisiUpdate: повторный приём того же update_id ---

    public function test_zapisi_update_with_same_update_id_is_processed_once(): void
    {
        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        Bus::fake();
        // Синглтон-строка уже создана в setUp — обновляем её, а не создаём вторую.
        MarketingSetting::query()->update(['zapisi_n8n_forward_url' => 'http://192.168.200.91/webhook/abc']);
        Cache::forget('marketing_setting.singleton');

        $update = [
            'update_id' => 903001,
            'message' => [
                'message_id' => 701,
                'date' => 1751360400,
                'text' => 'Название занятия',
                'chat' => ['id' => -1009988, 'type' => 'supergroup'],
                'from' => ['id' => 42],
            ],
        ];

        $job = new ProcessTelegramZapisiUpdate($update);
        $job->handle();
        $job->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class, 1);
    }

    // --- Ределивери вебхука основного бота ---

    public function test_main_bot_webhook_redelivery_answers_ok_without_reprocessing(): void
    {
        config([
            'services.telegram.bot_webhook_secret' => 'wave2-secret',
            'services.telegram.student_bot_token' => 'STUDENT-TOKEN',
        ]);

        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $payload = [
            'update_id' => 904001,
            'message' => [
                'message_id' => 801,
                'date' => 1751360400,
                'text' => '/start мёртвый-токен',
                'chat' => ['id' => 424242, 'type' => 'private'],
                'from' => ['id' => 424242, 'first_name' => 'Гость'],
            ],
        ];
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'wave2-secret'];

        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk();
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk();

        // Приветствие «ссылка устарела» ушло один раз на два одинаковых апдейта.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botSTUDENT-TOKEN/sendMessage')
            && str_contains((string) $request['text'], 'устарела'));
    }

    // --- ZapisiWebhookController остаётся тонким (быстрый 200), дедуп в джобе ---

    public function test_zapisi_webhook_controller_still_dispatches_raw_update(): void
    {
        Bus::fake();

        $controller = new TelegramZapisiWebhookController;
        $response = $controller->handle(
            \Illuminate\Http\Request::create('/', 'POST', [], [], [], [], json_encode(['update_id' => 905001])),
        );

        $this->assertTrue($response->getData(true)['ok']);
        Bus::assertDispatched(ProcessTelegramZapisiUpdate::class);
    }
}
