<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\LandingPage;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    // МЕТОД 1: Витрина со всеми курсами
    public function index()
    {
        $courses = Course::where('is_visible', true)
            ->with(['tariffs' => function ($query) {
                $query->where('is_active', true)->orderBy('price', 'asc');
            }])
            ->get();

        $page = new LandingPage([
            'title' => 'Магазин курсов',
            'description' => 'Выберите курс и начните обучение'
        ]);

        return view('shop.index', compact('courses', 'page'));
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