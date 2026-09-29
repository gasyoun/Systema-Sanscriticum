<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API для n8n-воркфлоу «Плашки занятий».
 *
 * GET  /api/lesson-banners/due — отрисованные плашки, ещё не доставленные на
 *      Диск (включая прошлые неудачи no_folder/error — повтор каждый проход),
 *      у занятий, которые ещё не прошли больше суток назад.
 * POST /api/lesson-banners/{banner}/delivered — отчёт n8n: delivered (+ id
 *      файла на Диске) | no_folder (для meeting_id нет строки в Automation_DB)
 *      | error (+ текст). Отчёт по устаревшему render_hash игнорируется:
 *      плашку уже перерисовали, и доставлять надо новую.
 */
final class LessonBannerController extends Controller
{
    public function due(): JsonResponse
    {
        $items = LessonBanner::query()
            ->with('schedule')
            ->where('render_status', LessonBanner::RENDERED)
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', LessonBanner::DELIVERED))
            ->whereHas('schedule', fn ($q) => $q->where('start', '>=', now()->subDay()))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (LessonBanner $banner): array => [
                'id' => $banner->id,
                'schedule_id' => $banner->schedule_id,
                'group_id' => $banner->schedule?->group_id,
                'meeting_id' => $banner->schedule?->zoom_meeting_id,
                'start' => $banner->schedule?->start?->toIso8601String(),
                'lesson_number' => $banner->lesson_number,
                'drive_filename' => $banner->drive_filename,
                'image_url' => $banner->imageUrl(),
                'render_hash' => $banner->render_hash,
                'previous_status' => $banner->delivery_status,
            ])
            ->values();

        return response()->json(['items' => $items]);
    }

    public function delivered(Request $request, LessonBanner $banner): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(LessonBanner::DELIVERY_STATUSES)],
            'render_hash' => ['required', 'string', 'max:64'],
            'drive_file_id' => ['nullable', 'string', 'max:255'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['render_hash'] !== $banner->render_hash) {
            return response()->json(['ok' => false, 'reason' => 'stale_render_hash'], 409);
        }

        $delivered = $data['status'] === LessonBanner::DELIVERED;

        $banner->forceFill([
            'delivery_status' => $data['status'],
            'delivery_error' => $delivered ? null : ($data['error'] ?? null),
            'drive_file_id' => $delivered ? ($data['drive_file_id'] ?? $banner->drive_file_id) : $banner->drive_file_id,
            'delivered_at' => $delivered ? now() : null,
        ])->save();

        return response()->json(['ok' => true]);
    }
}
