<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DownloadTelegramZapisiMedia;
use App\Jobs\ForwardUpdateToN8n;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProcessTelegramZapisiUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = storage_path('framework/testing/zapisi-'.uniqid());
        config(['services.telegram_harvest.store_path' => $this->store]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    public function test_text_message_is_normalized_and_stored_tagged_as_bot(): void
    {
        (new ProcessTelegramZapisiUpdate([
            'message' => [
                'message_id' => 501,
                'date' => 1751360400,
                'text' => 'Занятие сегодня в 18:00',
                'chat' => ['id' => -1009988, 'type' => 'supergroup', 'title' => 'Zapisi chat'],
                'from' => ['id' => 42, 'first_name' => 'Иван', 'username' => 'ivan'],
            ],
        ]))->handle();

        $file = $this->store.'/corpus/-1009988/2025-07-01.jsonl';
        $this->assertFileExists($file);

        $record = json_decode(trim(File::get($file)), true);
        $this->assertSame(501, $record['telegram_message_id']);
        $this->assertSame('bot', $record['account_type']);
        $this->assertSame('Иван', $record['author_name']);
        $this->assertSame('ivan', $record['author_username']);
        $this->assertFalse($record['has_media']);
        $this->assertSame('Занятие сегодня в 18:00', $record['text']);
    }

    /**
     * У бота ровно один вебхук: пока его держал n8n, наш прод не видел сообщений.
     * Теперь вебхук наш, а n8n получает тот же поток форвардом — иначе его
     * сценарий записи названий остался бы без данных.
     */
    public function test_update_is_forwarded_to_n8n_when_url_is_set(): void
    {
        Bus::fake();
        MarketingSetting::create(['zapisi_n8n_forward_url' => 'http://192.168.200.91/webhook/abc']);

        $update = [
            'message' => [
                'message_id' => 601,
                'date' => 1751360400,
                'text' => 'Тема занятия',
                'chat' => ['id' => -1009988, 'type' => 'supergroup'],
                'from' => ['id' => 42],
            ],
        ];

        (new ProcessTelegramZapisiUpdate($update))->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class, fn (ForwardUpdateToN8n $job): bool => $job->url === 'http://192.168.200.91/webhook/abc'
            && $job->update === $update);
    }

    /**
     * Форвард идёт ДО фильтров: сообщение без текста и медиа наш корпус
     * отбрасывает, но n8n сам решает, что ему интересно.
     */
    public function test_update_filtered_out_locally_is_still_forwarded(): void
    {
        Bus::fake();
        MarketingSetting::create(['zapisi_n8n_forward_url' => 'http://192.168.200.91/webhook/abc']);

        (new ProcessTelegramZapisiUpdate([
            'message' => [
                'message_id' => 602,
                'date' => 1751360400,
                'chat' => ['id' => -1009988, 'type' => 'supergroup'],
                'from' => ['id' => 42],
                // ни текста, ни медиа — в корпус не попадёт
            ],
        ]))->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class);
    }

    /** Адрес не задан — ничего никуда не шлём. */
    public function test_no_forward_when_url_is_empty(): void
    {
        Bus::fake();
        MarketingSetting::create(['zapisi_n8n_forward_url' => null]);

        (new ProcessTelegramZapisiUpdate([
            'message' => [
                'message_id' => 603,
                'date' => 1751360400,
                'text' => 'Привет',
                'chat' => ['id' => -1009988, 'type' => 'supergroup'],
                'from' => ['id' => 42],
            ],
        ]))->handle();

        Bus::assertNotDispatched(ForwardUpdateToN8n::class);
    }

    public function test_media_message_dispatches_download_job(): void
    {
        Bus::fake();

        (new ProcessTelegramZapisiUpdate([
            'message' => [
                'message_id' => 502,
                'date' => 1751360400,
                'caption' => 'фото с занятия',
                'chat' => ['id' => -1009988, 'type' => 'supergroup'],
                'from' => ['id' => 42, 'first_name' => 'Иван'],
                'photo' => [
                    ['file_id' => 'small_id', 'file_size' => 100],
                    ['file_id' => 'large_id', 'file_size' => 5000],
                ],
            ],
        ]))->handle();

        Bus::assertDispatched(DownloadTelegramZapisiMedia::class, fn (DownloadTelegramZapisiMedia $job): bool => $job->chatId === -1009988
            && $job->messageId === 502
            && $job->fileId === 'large_id'
            && $job->mediaType === 'photo');
    }

    public function test_empty_update_without_message_is_ignored(): void
    {
        (new ProcessTelegramZapisiUpdate(['edited_message' => ['message_id' => 1]]))->handle();
        // No exception, nothing written.
        $this->assertDirectoryDoesNotExist($this->store.'/corpus');
    }
}
