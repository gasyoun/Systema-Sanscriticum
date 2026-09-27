<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TelegramBusiness\TelegramBusinessStoryPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Video download, transcode and upload must not hold the 120-second webhook worker. */
final class PublishTelegramBusinessStory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

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
}
