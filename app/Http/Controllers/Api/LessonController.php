<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LessonController extends Controller
{
    public function sync(Request $request)
    {
        // Проверка ключа (fail-closed)
        $secret = config('services.lesson_sync.secret');

        if (empty($secret) || !hash_equals($secret, (string) $request->header('X-Secret-Key'))) {
            Log::warning('Lesson sync: неавторизованный доступ', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $courses = $request->validate([
            '*.id'           => 'required|integer',
            '*.title'        => 'required|string|max:255',
            '*.videoLinks'   => 'nullable|array',
            '*.rutubeLinks'  => 'nullable|array',
            '*.lessonTopics' => 'nullable|array',
            '*.flashCards'   => 'nullable|array',
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
}