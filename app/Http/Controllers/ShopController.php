<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\LandingPage;
use Illuminate\Http\Request;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    // МЕТОД 1: Витрина со всеми курсами
    public function index(Request $request)
{
    $search = $request->input('search');

    $courses = Course::where('is_visible', true)
        ->when($search, fn ($q, $s) => $q->where('title', 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%'))
        ->with(['tariffs' => function ($query) {
            $query->where('is_active', true)->orderBy('price', 'asc');
        }])
        ->paginate(9)
        ->withQueryString();

    // Предзагружаем купленные ключи по всем курсам на странице — одним запросом
    $purchasedByCourse = [];
    if (Auth::check()) {
        $courseIds = $courses->pluck('id')->all();

        $purchasedByCourse = Payment::query()
            ->where('user_id', Auth::id())
            ->whereIn('course_id', $courseIds)
            ->whereIn('status', ['paid', 'success'])
            ->get(['course_id', 'tariff'])
            ->groupBy('course_id')
            ->map(fn ($rows) => $rows->pluck('tariff')->filter()->unique()->values()->all())
            ->all();
    }

    $page = new LandingPage([
        'title' => 'Общество ревнителей санскрита',
        'description' => 'Выберите курс и начните обучение',
    ]);

    return view('shop.index', compact('courses', 'page', 'search', 'purchasedByCourse'));
}

    // МЕТОД 2: Страница одного конкретного курса
    public function show(Course $course)
{
    if (!$course->is_visible) {
        abort(404, 'Курс не найден');
    }

    $course->load([
    'tariffs' => function ($query) {
        $query->where('is_active', true)->orderBy('price', 'asc');
    },
    'tariffs.block',
    'blocks',
    'teacher', // подгружаем преподавателя одним запросом
]);

    $currentBlock = $course->currentBlock();
    $currentBlockNumber = $currentBlock?->number;

    // Собираем массив купленных тарифов ОДНИМ запросом (без N+1)
    $purchasedKeys = [];
    if (Auth::check()) {
        $purchasedKeys = Payment::query()
            ->where('user_id', Auth::id())
            ->where('course_id', $course->id)
            ->whereIn('status', ['paid', 'success'])
            ->pluck('tariff')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    $page = new LandingPage(['title' => $course->title]);

    return view('shop.show', compact('course', 'page', 'purchasedKeys', 'currentBlock', 'currentBlockNumber'));
}
}