<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 152-ФЗ: превью YouTube со своего сервера. Раньше карточки ссылались прямо
 * на img.youtube.com — и Google получал IP каждого посетителя страницы.
 * Теперь картинку один раз скачивает сервер (его IP, не посетителя),
 * кладёт в storage/app/public/video-thumbs и дальше отдаёт из кэша.
 */
class VideoThumbController extends Controller
{
    private const DIR = 'video-thumbs';

    public function show(string $id): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1, 404);

        $disk = Storage::disk('public');
        $path = self::DIR.'/'.$id.'.jpg';

        if (! $disk->exists($path)) {
            try {
                $res = Http::timeout(5)->get("https://img.youtube.com/vi/{$id}/hqdefault.jpg");
            } catch (\Throwable $e) {
                Log::info('video-thumb: fetch failed', ['id' => $id, 'error' => $e->getMessage()]);
                abort(404);
            }

            abort_unless($res->successful() && str_starts_with((string) $res->header('Content-Type'), 'image/'), 404);
            $disk->put($path, $res->body());
        }

        return response($disk->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=2592000, immutable',
        ]);
    }
}
