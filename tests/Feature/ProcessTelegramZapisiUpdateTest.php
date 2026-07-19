<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DownloadTelegramZapisiMedia;
use App\Jobs\ProcessTelegramZapisiUpdate;
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
