<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StudentController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
*/

// Главная страница (пока оставляем стандартную или редирект на логин)
Route::get('/login', function () {
    return redirect()->route('filament.admin.auth.login');
})->name('login');

Route::get('/', function () {
    return view('welcome');
});

// Группа маршрутов для Личного кабинета (только для авторизованных пользователей)
Route::middleware(['auth'])->group(function () {
    
    // 1. Главная страница кабинета (Список курсов)
    Route::get('/cabinet', [StudentController::class, 'dashboard'])
        ->name('student.dashboard');
    
    // 2. Страница курса (Первый урок или список)
    Route::get('/course/{slug}', [StudentController::class, 'showCourse'])
        ->name('student.course');
    
    // 3. Страница конкретного урока внутри курса
    Route::get('/course/{slug}/lesson/{lessonId}', [StudentController::class, 'showCourse'])
        ->name('student.lesson');
});
