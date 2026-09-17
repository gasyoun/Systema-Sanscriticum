<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class TelegramFetchStoryMediaCommand extends Command
{
    protected $signature = 'telegram:fetch-story-media
        {peer : Username, numeric peer ID, or dialog title}
        {--account=marcisgasuns : Enabled account that can read the source dialog}
        {--message= : Source message ID; default: latest message with media}
        {--output= : Absolute output directory}';

    protected $description = 'Download a media attachment from an accessible Telegram dialog for a Story';

    public function handle(MadelineClientFactory $factory): int
    {
        $account = TelegramSupportAccount::query()->where('name', (string) $this->option('account'))->where('is_enabled', true)->firstOrFail();
        $client = $factory->open(null, (string) $account->session_path);
        $peer = (string) $this->argument('peer');
        $messageId = $this->option('message');
        $history = $client->messages->getHistory(['peer' => $peer, 'limit' => $messageId ? 1 : 100, 'offset_id' => $messageId ? ((int) $messageId + 1) : 0]);
        $message = collect($history['messages'] ?? [])->first(fn ($m) => is_array($m) && ($messageId ? (int) ($m['id'] ?? 0) === (int) $messageId : ! empty($m['media'])));
        if (! is_array($message) || empty($message['media'])) throw new RuntimeException('No media message found.');
        $dir = (string) ($this->option('output') ?: storage_path('app/telegram-stories/source'));
        File::ensureDirectoryExists($dir);
        $path = $client->downloadToDir($message['media'], $dir);
        $this->line((string) $path);
        return self::SUCCESS;
    }
}
