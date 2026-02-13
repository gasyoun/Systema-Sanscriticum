<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    /**
     * Список курсов
     */
    public function dashboard()
    {
        // Получаем курсы, которые отмечены как видимые
        $courses = Course::where('is_visible', true)->get();

        return view('student.dashboard', compact('courses'));
    }

    /**
     * Просмотр курса и уроков
     */
    public function showCourse($slug, $lessonId = null)
    {
        $course = Course::where('slug', $slug)
            ->where('is_visible', true)
            ->firstOrFail();

        $lessons = $course->lessons()->orderBy('created_at', 'asc')->get();

        // Если ID не передан, берем первый урок курса
        $currentLesson = $lessonId 
            ? Lesson::findOrFail($lessonId) 
            : $lessons->first();

        return view('student.course', compact('course', 'lessons', 'currentLesson'));
    }
}
