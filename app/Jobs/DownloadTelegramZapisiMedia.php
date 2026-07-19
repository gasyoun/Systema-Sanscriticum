<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketingSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Track C (H164, D11 override): downloads the actual media file for a
 * zapisi_ORSbot message via the Bot API (getFile + raw download), landing it
 * in the SAME out-of-git store as the raw text (never committed, PII/rights
 * per the Legal gate — this is a private booking chat, not a publishable
 * group). D4's metadata-only default is unchanged for the rest of Track B.
 */
class DownloadTelegramZapisiMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly string $fileId,
        public readonly ?string $mediaType,
        public readonly string $date,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '') {
            return;
        }

        $getFile = Http::get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $this->fileId]);
        if (! $getFile->successful() || ! ($getFile->json('ok') ?? false)) {
            Log::warning('DownloadTelegramZapisiMedia: getFile failed', ['chat_id' => $this->chatId, 'message_id' => $this->messageId, 'status' => $getFile->status()]);

            return;
        }

        $filePath = (string) ($getFile->json('result.file_path') ?? '');
        if ($filePath === '') {
            return;
        }

        $download = Http::get("https://api.telegram.org/file/bot{$token}/{$filePath}");
        if (! $download->successful()) {
            Log::warning('DownloadTelegramZapisiMedia: file download failed', ['chat_id' => $this->chatId, 'message_id' => $this->messageId, 'status' => $download->status()]);

            return;
        }

        $dir = $this->mediaStorePath().'/'.$this->chatId.'/'.$this->date;
        File::ensureDirectoryExists($dir);

        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'bin';
        $dest = $dir.'/'.$this->messageId.'_'.($this->mediaType ?: 'file').'.'.$extension;
        File::put($dest, $download->body());
    }

    private function mediaStorePath(): string
    {
        $base = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));

        return $base.'/zapisi_media';
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DownloadTelegramZapisiMedia failed permanently', [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'error' => $e->getMessage(),
        ]);
    }
}
