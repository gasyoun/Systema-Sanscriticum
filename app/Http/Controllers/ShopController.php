<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\LandingPage;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    // МЕТОД 1: Витрина со всеми курсами
    public function index(Request $request)
    {
        // Получаем строку поиска из URL (если она есть)
        $search = $request->input('search');

        $courses = Course::where('is_visible', true)
            // Если есть запрос поиска, фильтруем по названию
            ->when($search, function ($query, $search) {
                return $query->where('title', 'LIKE', "%{$search}%");
            })
            ->with(['tariffs' => function ($query) {
                $query->where('is_active', true)->orderBy('price', 'asc');
            }])
            ->paginate(9)
            ->withQueryString(); // Важно! Сохраняет параметры поиска при переходе по страницам пагинации

        $page = new LandingPage([
            'title' => 'Общество ревнителей санскрита',
            'description' => 'Выберите курс и начните обучение'
        ]);

        return view('shop.index', compact('courses', 'page', 'search'));
    }

    // МЕТОД 2: Страница одного конкретного курса
    public function show(Course $course)
    {
        // Если курс скрыт, выдаем 404 ошибку
        if (!$course->is_visible) {
            abort(404, 'Курс не найден');
        }

        // Подгружаем активные тарифы
        $course->load(['tariffs' => function ($query) {
            $query->where('is_active', true)->orderBy('price', 'asc');
        }]);

        // Заглушка для шаблона promo (чтобы не было ошибки $page)
        $page = new LandingPage([
            'title' => $course->title,
        ]);

        return view('shop.show', compact('course', 'page'));
    }
}