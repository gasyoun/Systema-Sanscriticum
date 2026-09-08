<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ForwardUpdateToN8n;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H4318 (MG «железно»): приветственная карточка — не чаще 1 раза в 24 ч на чат.
 * Клейм в Redis ДО форварда; Redis недоступен = FAIL-CLOSED (не шлём).
 */
class ZapisiWelcomeRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = storage_path('framework/testing/zapisi-ratelimit-'.uniqid());
        config(['services.telegram_harvest.store_path' => $this->store]);
        $this->claimedKeys = [];
        Bus::fake();
        MarketingSetting::create([
            'zapisi_n8n_forward_url' => 'http://192.168.200.91/webhook/old',
            'zapisi_welcome_n8n_url' => 'http://192.168.200.91/webhook/welcome',
        ]);
        // Локальная машина без redis-сервера/расширения: клейм эмулируем через
        // Mockery-фейк фасада (NX-семантика проверяется отдельным тестом на проде).
        $this->fakeRedisClaim();
    }

    private array $claimedKeys = [];

    private function fakeRedisClaim(): void
    {
        $self = $this;
        $fake = \Mockery::mock();
        $fake->shouldReceive('set')
            ->andReturnUsing(function (string $key, ...$rest) use ($self) {
                if (in_array($key, $self->claimedKeys, true)) {
                    return false; // NX-семантика: второй клейм того же ключа отклонён
                }
                $self->claimedKeys[] = $key;

                return true;
            });
        $manager = \Mockery::mock(RedisManager::class)->makePartial();
        $manager->shouldReceive('connection')->andReturn($fake);
        $this->app->instance('redis', $manager);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    private function update(int $updateId, int $chatId = -1009988): array
    {
        return [
            'update_id' => $updateId,
            'my_chat_member' => [
                'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'T'],
                'from' => ['id' => 42],
                'old_chat_member' => ['status' => 'left'],
                'new_chat_member' => ['status' => 'administrator', 'user' => ['id' => 555, 'is_bot' => true]],
            ],
        ];
    }

    public function test_first_welcome_update_forwards(): void
    {
        (new ProcessTelegramZapisiUpdate($this->update(1)))->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class);
    }

    public function test_second_welcome_update_same_chat_within_24h_is_dropped(): void
    {
        (new ProcessTelegramZapisiUpdate($this->update(2)))->handle();
        (new ProcessTelegramZapisiUpdate($this->update(3)))->handle();

        Bus::assertDispatchedTimes(ForwardUpdateToN8n::class, 1);
    }

    public function test_different_chats_are_independent(): void
    {
        (new ProcessTelegramZapisiUpdate($this->update(4, -1009988)))->handle();
        (new ProcessTelegramZapisiUpdate($this->update(5, -1007777)))->handle();

        Bus::assertDispatchedTimes(ForwardUpdateToN8n::class, 2);
    }

    public function test_message_updates_are_never_rate_limited(): void
    {
        $message = [
            'message' => [
                'message_id' => 900,
                'date' => 1751360400,
                'text' => 'тема занятия',
                'chat' => ['id' => -1009988, 'type' => 'supergroup'],
                'from' => ['id' => 42],
            ],
        ];
        (new ProcessTelegramZapisiUpdate($message))->handle();
        (new ProcessTelegramZapisiUpdate($message))->handle();

        Bus::assertDispatchedTimes(ForwardUpdateToN8n::class, 2);
    }

    public function test_redis_unavailable_fails_closed_no_forward(): void
    {
        // Redis недоступен: любой вызов set бросает — джоба обязана fail-closed.
        $fake = \Mockery::mock();
        $fake->shouldReceive('set')->andThrow(new \Exception('redis down'));
        $manager = \Mockery::mock(RedisManager::class)->makePartial();
        $manager->shouldReceive('connection')->andReturn($fake);
        $this->app->instance('redis', $manager);

        (new ProcessTelegramZapisiUpdate($this->update(6)))->handle();

        Bus::assertNotDispatched(ForwardUpdateToN8n::class);
    }
}
