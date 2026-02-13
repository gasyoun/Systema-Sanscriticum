<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    public function sync(Request $request)
    {
        // Проверка вашего ключа
        if ($request->header('X-Secret-Key') !== 'Ivan3128211498') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $courses = $request->all();

        foreach ($courses as $course) {
            $courseId = $course['id'];
            $title = $course['title'];

            // Собираем все даты из видео и тем
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
                        'flash_cards' => isset($course['flashCards'][$date]) ? json_encode($course['flashCards'][$date]) : null,
                    ]
                );
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Database synchronized']);
    }
}
