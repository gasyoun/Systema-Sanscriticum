<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Teacher;
use App\Services\Membership\PrivateArchiveEligibility;
use Illuminate\Contracts\View\View;

/**
 * Публичные страницы преподавателей ОРС: список карточек /prepodavately и
 * страница каждого преподавателя /prepodavately/{slug} — в оформлении витрины.
 * Наружу отдаём только то, что админ включил в Filament (page_enabled + slug).
 */
class TeacherPublicPageController extends Controller
{
    public function index(): View
    {
        // Ручной порядок (page_sort), при равенстве — по имени; пагинация не нужна:
        // преподавателей у школы единицы, а карточки легковесные.
        $teachers = Teacher::query()
            ->withPublicPage()
            ->orderBy('page_sort')
            ->orderBy('name')
            ->get();

        return view('teachers.index', compact('teachers'));
    }

    public function show(string $slug): View
    {
        $teacher = Teacher::query()
            ->withPublicPage()
            ->where('page_slug', $slug)
            ->firstOrFail();

        // Курсы преподавателя: основной ИЛИ со-препод (тот же контур, что у
        // преподавательского кабинета), только витринные — то, что реально
        // откроется по /k/{slug}. Готово к <x-shop.course-card>: активные
        // тарифы, категории и преподаватель загружены одним запросом на связь.
        $courses = PrivateArchiveEligibility::scopePublic(Course::query())
            ->where('is_visible', true)
            ->where('is_active', true)
            ->forTeacher($teacher->id)
            ->with([
                'tariffs' => fn ($query) => $query->where('is_active', true)->orderBy('price'),
                'categories',
                'teacher',
            ])
            ->orderBy('title')
            ->get();

        return view('teachers.show', compact('teacher', 'courses'));
    }
}
