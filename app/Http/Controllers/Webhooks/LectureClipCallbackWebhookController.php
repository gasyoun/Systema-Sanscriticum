<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * n8n зовёт этот эндпоинт после ffmpeg-нарезки + VK-аплоада (H1452, Wave 4):
 * записывает одну LectureClip-строку на каждый готовый фрагмент. Секрет и
 * идемпотентность (по lesson_id+start_seconds+end_seconds) — здесь; сам
 * ffmpeg/VK — целиком в n8n, эта модель только ЗАПИСЬ результата.
 *
 * Gated by config('features.clip_marketing'); OFF по умолчанию — маршрут
 * отвечает 404, как ReadingPackController/kosha_reader.
 */
final class LectureClipCallbackWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        abort_if(! config('features.clip_marketing', false), 404);

        $data = $request->validate([
            'lesson_id' => 'required|integer|exists:lessons,id',
            'clips' => 'required|array|min:1',
            'clips.*.start_seconds' => 'required|integer|min:0',
            'clips.*.end_seconds' => 'required|integer|gt:clips.*.start_seconds',
            'clips.*.title' => 'required|string|max:255',
            'clips.*.vk_video_id' => 'nullable|string|max:255',
            'clips.*.vk_owner_id' => 'nullable|string|max:255',
        ]);

        $lesson = Lesson::findOrFail($data['lesson_id']);

        $created = 0;
        foreach ($data['clips'] as $clip) {
            $row = $lesson->clips()->firstOrCreate([
                'start_seconds' => $clip['start_seconds'],
                'end_seconds' => $clip['end_seconds'],
            ], [
                'title' => $clip['title'],
                'vk_video_id' => $clip['vk_video_id'] ?? null,
                'vk_owner_id' => $clip['vk_owner_id'] ?? null,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return response()->json(['ok' => true, 'received' => count($data['clips']), 'created' => $created]);
    }
}
