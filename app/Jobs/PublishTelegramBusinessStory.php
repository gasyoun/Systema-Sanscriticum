<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TelegramBusiness\TelegramBusinessStoryPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** Video download, transcode and upload must not hold the 120-second webhook worker. */
final class PublishTelegramBusinessStory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 600;

    /** @param array<string, mixed> $post */
    public function __construct(public readonly array $post)
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
        $this->onQueue('imports');
    }

    public function handle(TelegramBusinessStoryPublisher $publisher): void
    {
        $publisher->publishFromChannelPost($this->post);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $chatId = (string) ($this->post['chat']['id'] ?? '');
        $messageId = (int) ($this->post['message_id'] ?? 0);

        return [(new WithoutOverlapping("telegram-business-story:{$chatId}:{$messageId}"))
            ->releaseAfter(60)->expireAfter(900)];
    }
}
