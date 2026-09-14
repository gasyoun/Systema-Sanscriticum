<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ForwardUpdateToN8n;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H4314: роутинг my_chat_member (бот добавлен в чат) на zapisi_welcome_n8n_url.
 * message/channel_post продолжают уходить в zapisi_n8n_forward_url; корпус
 * my_chat_member-апдейт не получает; пустой welcome-URL = форварда нет.
 */
class ZapisiWelcomeRoutingTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = storage_path('framework/testing/zapisi-welcome-'.uniqid());
        config(['services.telegram_harvest.store_path' => $this->store]);
        // H4318: my_chat_member клеймится в Redis до форварда; CI runner без
        // redis-сервера получал Connection refused → fail-closed → форварда нет.
        // Эмулируем живой Redis с NX-семантикой на уровне фасада.
        $claimed = [];
        Redis::shouldReceive('set')
            ->andReturnUsing(function (string $key, ...$rest) use (&$claimed) {
                if (in_array($key, $claimed, true)) {
                    return false;
                }
                $claimed[] = $key;

                return true;
            });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    private function myChatMemberUpdate(): array
    {
        return [
            'update_id' => 771001,
            'my_chat_member' => [
                'chat' => ['id' => -1009988, 'type' => 'supergroup', 'title' => 'Sandbox'],
                'from' => ['id' => 42, 'first_name' => 'МГ'],
                'old_chat_member' => ['status' => 'left'],
                'new_chat_member' => ['status' => 'administrator', 'user' => ['id' => 555, 'username' => 'zapisi_ORSbot']],
            ],
        ];
    }

    public function test_my_chat_member_routes_to_welcome_url_and_not_old_url(): void
    {
        Bus::fake();
        MarketingSetting::create([
            'zapisi_n8n_forward_url' => 'http://192.168.200.91/webhook/old',
            'zapisi_welcome_n8n_url' => 'http://192.168.200.91/webhook/welcome',
        ]);

        $update = $this->myChatMemberUpdate();

        (new ProcessTelegramZapisiUpdate($update))->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class, fn (ForwardUpdateToN8n $job): bool => $job->url === 'http://192.168.200.91/webhook/welcome'
            && $job->update === $update);

        Bus::assertNotDispatched(ForwardUpdateToN8n::class, fn (ForwardUpdateToN8n $job): bool => $job->url === 'http://192.168.200.91/webhook/old');
    }

    public function test_message_still_routes_to_old_url(): void
    {
        Bus::fake();
        MarketingSetting::create([
            'zapisi_n8n_forward_url' => 'http://192.168.200.91/webhook/old',
            'zapisi_welcome_n8n_url' => 'http://192.168.200.91/webhook/welcome',
        ]);

        (new ProcessTelegramZapisiUpdate([
            'message' => [
                'message_id' => 771,
                'date' => 1751360400,
                'text' => 'Тема занятия',
                'chat' => ['id' => -1009988, 'type' => 'supergroup'],
                'from' => ['id' => 42],
            ],
        ]))->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class, fn (ForwardUpdateToN8n $job): bool => $job->url === 'http://192.168.200.91/webhook/old');
        Bus::assertNotDispatched(ForwardUpdateToN8n::class, fn (ForwardUpdateToN8n $job): bool => $job->url === 'http://192.168.200.91/webhook/welcome');
    }

    public function test_my_chat_member_writes_no_corpus_row(): void
    {
        Bus::fake();
        MarketingSetting::create([
            'zapisi_welcome_n8n_url' => 'http://192.168.200.91/webhook/welcome',
        ]);

        (new ProcessTelegramZapisiUpdate($this->myChatMemberUpdate()))->handle();

        $this->assertDirectoryDoesNotExist($this->store.'/corpus');
    }

    public function test_my_chat_member_not_forwarded_when_welcome_url_empty(): void
    {
        Bus::fake();
        MarketingSetting::create([
            'zapisi_n8n_forward_url' => 'http://192.168.200.91/webhook/old',
        ]);

        (new ProcessTelegramZapisiUpdate($this->myChatMemberUpdate()))->handle();

        Bus::assertNotDispatched(ForwardUpdateToN8n::class);
    }
}
