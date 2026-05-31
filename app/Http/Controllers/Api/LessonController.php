<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\VideoLinkNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LessonController extends Controller
{
    public function sync(Request $request)
    {
        if ($unauthorized = $this->guard($request)) {
            return $unauthorized;
        }

        $courses = $request->validate([
            '*.id' => 'required|integer',
            '*.title' => 'required|string|max:255',
            '*.videoLinks' => 'nullable|array',
            '*.rutubeLinks' => 'nullable|array',
            '*.lessonTopics' => 'nullable|array',
            '*.flashCards' => 'nullable|array',
        ]);

        foreach ($courses as $course) {
            $courseId = $course['id'];
            $title = $course['title'];

            $dates = array_unique(array_merge(
                array_keys($course['videoLinks'] ?? []),
                array_keys($course['lessonTopics'] ?? [])
            ));

            foreach ($dates as $date) {
                Lesson::updateOrCreate(
                    ['course_id' => $courseId, 'lesson_date' => $date],
                    [
                        'title' => $title,
                        'video_url' => $course['videoLinks'][$date] ?? null,
                        'rutube_url' => $course['rutubeLinks'][$date] ?? null,
                        'topic' => $course['lessonTopics'][$date] ?? null,
                        'flash_cards' => $course['flashCards'][$date] ?? null,
                    ]
                );
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Database synchronized']);
    }

    /**
     * Создание одиночного урока из n8n-сценария обработки записи Zoom.
     * Идемпотентно по (course_id, lesson_date): повторный прогон того же
     * исполнения (та же Zoom start_time) обновляет запись, а не плодит дубль.
     */
    public function storeFromZoom(Request $request, VideoLinkNormalizer $links)
    {
        if ($unauthorized = $this->guard($request)) {
            return $unauthorized;
        }

        $data = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'title' => 'required|string|max:255',
            'lesson_date' => 'required|date',
            'block_number' => 'nullable|integer|min:1',
            'youtube_url' => 'nullable|string',
            'rutube_url' => 'nullable|string',
            'topic' => 'nullable|string',
            // Длительность в секундах. Сценарий отдаёт минуты — конвертируем ниже.
            'duration_seconds' => 'nullable|integer|min:0',
            'duration_minutes' => 'nullable|integer|min:0',
        ]);

        // Нормализуем ссылки: youtube → https://youtu.be/{id};
        // rutube → каноническая ссылка с сохранением приватного токена ?p=.
        $youtube = $links->youtube($data['youtube_url'] ?? null);
        $rutube = $links->rutube($data['rutube_url'] ?? null);

        // duration: принимаем либо секунды, либо минуты (сценарий шлёт минуты).
        $durationSeconds = $data['duration_seconds']
            ?? (isset($data['duration_minutes']) ? $data['duration_minutes'] * 60 : null);

        // lesson_date в модели кастится в date (время отбрасывается). Ищем через
        // whereDate, иначе сравнение сырой ISO-строки с сохранённым значением
        // (Eloquent пишет date-каст как 'Y-m-d 00:00:00') не совпадёт и урок
        // продублируется при повторном прогоне того же исполнения.
        $lessonDate = \Illuminate\Support\Carbon::parse($data['lesson_date'])->toDateString();

        $lesson = Lesson::query()
            ->where('course_id', $data['course_id'])
            ->whereDate('lesson_date', $lessonDate)
            ->first();

        $wasCreated = $lesson === null;
        $lesson ??= new Lesson([
            'course_id' => $data['course_id'],
            'lesson_date' => $lessonDate,
        ]);

        $lesson->fill([
            'title' => $data['title'],
            'topic' => $data['topic'] ?? null,
            'block_number' => $data['block_number'] ?? 1,
            'youtube_url' => $youtube,
            'rutube_url' => $rutube,
            'duration_seconds' => $durationSeconds,
            'is_published' => true,
            'is_free' => false,
        ])->save();

        Log::info('Lesson from Zoom: урок сохранён', [
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'created' => $wasCreated,
        ]);

        return response()->json([
            'status' => 'success',
            'lesson_id' => $lesson->id,
            'created' => $wasCreated,
        ]);
    }

    /**
     * Fail-closed авторизация по общему секрету LESSON_SYNC_SECRET.
     * Возвращает 401-ответ при отказе либо null, если доступ разрешён.
     */
    private function guard(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $secret = config('services.lesson_sync.secret');

        if (empty($secret) || ! hash_equals($secret, (string) $request->header('X-Secret-Key'))) {
            Log::warning('Lesson sync: неавторизованный доступ', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return null;
    }
}
