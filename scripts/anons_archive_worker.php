<?php

declare(strict_types=1);

/**
 * Архивный воркер H5049 R11: ИЗОЛИРОВАННЫЙ процесс для чтения
 * stories.getStoriesArchive + скачивания медиа (Amp-цикл MadelineProto
 * из-под artisan ненадёжен — та же причина, что у stories_lane_worker).
 *
 * Контракт: argv[1] — JSON {"account":"rusamskrtam","limit":100}
 * stdout — ровно ОДНА строка JSON:
 *   {"ok":true,"total":N,"items":[{remote_id,date,expire_date,caption,url,
 *    media_path,media_hash,phash,dimensions}]} | {"ok":false,"error":"..."}
 */

use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineClientFactory;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function fail(string $message): never
{
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}

$task = json_decode((string) ($argv[1] ?? ''), true);
if (! is_array($task)) {
    fail('invalid task json');
}

$account = (string) ($task['account'] ?? 'rusamskrtam');
$limit = max(1, min(500, (int) ($task['limit'] ?? 100)));
$storageDir = storage_path('app/anons/archive/'.$account);
if (! is_dir($storageDir) && ! mkdir($storageDir, 0775, true) && ! is_dir($storageDir)) {
    fail("cannot create {$storageDir}");
}

try {
    $factory = app(MadelineClientFactory::class);
    if (! $factory->isConfigured()) {
        fail('MadelineProto is not configured');
    }

    if ($account !== 'rusamskrtam') {
        $row = TelegramSupportAccount::query()
            ->where('name', $account)->where('is_enabled', true)->firstOrFail();
        $client = $factory->open(null, $row->session_path);
    } else {
        $client = $factory->open();
    }

    $archive = $client->stories->getStoriesArchive([
        'peer' => 'me',
        'limit' => $limit,
    ]);

    $items = [];
    foreach (($archive['stories'] ?? []) as $story) {
        if (! is_array($story) || ($story['_'] ?? '') !== 'storyItem') {
            continue;
        }
        $item = [
            'remote_id' => (string) ($story['id'] ?? ''),
            'date' => $story['date'] ?? null,
            'expire_date' => $story['expire_date'] ?? null,
            'caption' => $story['caption'] ?? '',
            'url' => $story['url'] ?? null,
        ];

        // Скачиваем медиа, если есть (photo/video) — для хэша и переиспользования.
        $media = $story['media'] ?? [];
        $fileRef = $media['photo'] ?? ($media['document'] ?? null);
        if (is_array($fileRef)) {
            try {
                $local = $storageDir.'/'.$item['remote_id'].(isset($media['document']) ? '.mp4' : '.jpg');
                $saved = $client->downloadToFile($fileRef, $local);
                if (is_string($saved) && is_file($saved)) {
                    $item['media_path'] = $saved;
                    $item['media_hash'] = hash_file('sha256', $saved) ?: null;
                    $item['dimensions'] = isset($fileRef['w'], $fileRef['h']) ? [(int) $fileRef['w'], (int) $fileRef['h']] : null;
                    $item['phash'] = dhashHex($saved);
                }
            } catch (Throwable $e) {
                $item['media_error'] = substr($e->getMessage(), 0, 200);
            }
        }

        $items[] = $item;
    }

    fwrite(STDOUT, json_encode(['ok' => true, 'total' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fail(substr($e->getMessage(), 0, 500));
}

/** 64-битный dhash (горизонтальный градиент) в hex — perceptual hash. */
function dhashHex(string $path): ?string
{
    $info = @getimagesize($path);
    $img = match ($info[2] ?? null) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        default => false,
    };
    if ($img === false) {
        return null;
    }
    $small = imagecreatetruecolor(9, 8);
    imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));

    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $left = imagecolorat($small, $x, $y) & 0xFF;
            $right = imagecolorat($small, $x + 1, $y) & 0xFF;
            $bits .= ($left > $right) ? '1' : '0';
        }
    }
    imagedestroy($img);
    imagedestroy($small);

    return substr(str_pad(base_convert(str_pad($bits, 64, '0'), 2, 16), 16, '0', STR_PAD_LEFT), 0, 16);
}
