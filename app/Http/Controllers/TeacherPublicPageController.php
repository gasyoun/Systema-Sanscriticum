<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\Teacher;
use App\Models\WaitlistVote;
use App\Services\Membership\PrivateArchiveEligibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

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

        // Ждун на странице преподавателя: курс без тарифов здесь тоже не должен
        // быть тупиком «Набор закрыт». Тот же скоуп, что в каталоге и на
        // /online/zhdun (is_listed, без closed/scheduled); флаг OFF — пустые
        // коллекции, карточки с прежним замком, лишних запросов нет.
        $waitlistByCourse = [];
        $votedWaitlistIds = [];
        if ((bool) config('features.waitlist_voting', false) && $courses->isNotEmpty()) {
            $waitlistItems = CourseWaitlistItem::query()
                ->where('is_listed', true)
                ->whereIn('course_id', $courses->pluck('id'))
                ->whereNotIn('status', [
                    CourseWaitlistItem::STATUS_CLOSED,
                    CourseWaitlistItem::STATUS_SCHEDULED,
                ])
                ->withCount('votes')
                ->get();

            $waitlistByCourse = $waitlistItems->keyBy('course_id')->all();

            if (Auth::check() && $waitlistItems->isNotEmpty()) {
                $votedWaitlistIds = WaitlistVote::query()
                    ->where('user_id', Auth::id())
                    ->whereIn('course_waitlist_item_id', $waitlistItems->modelKeys())
                    ->pluck('course_waitlist_item_id')
                    ->all();
            }
        }

        return view('teachers.show', compact('teacher', 'courses', 'waitlistByCourse', 'votedWaitlistIds'));
    }
}
