<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\PublicScheduleController;
use App\Models\Course;
use App\Services\Schedule\FullSchedulePost;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Голый, встраиваемый в iframe виджет расписания (H1427, wave 1b).
 *
 * Отдаёт standalone HTML-документ (без layout сайта, без @vite, без auth). Данные
 * подтягивает клиентский JS с {@see PublicScheduleController}
 * (`/api/public/schedule`). Заголовок `Content-Security-Policy: frame-ancestors`
 * выставляется ТОЛЬКО на этом ответе — глобального CSP/X-Frame-Options в проекте
 * нет, поэтому это по построению локальная область: ничего site-wide не ослабляется.
 */
class PublicWidgetController extends Controller
{
    /**
     * Origin'ы, которым разрешено встраивать виджет в iframe.
     */
    private const FRAME_ANCESTORS = "frame-ancestors 'self' https://samskrtam.ru https://www.samskrtam.ru";

    public function schedule(Request $request): Response
    {
        return response()
            ->view('widgets.schedule', [
                'feedUrl' => route('api.public.schedule'),
                // H4328: полные расписания курсов (обзорное + занятия 1–N)
                // тем же билдером, что и Telegram-пост. За тем же флагом.
                'fullSchedulePosts' => $this->fullSchedulePosts(),
                // H4434: клиентская конверсия в зону устройства гостя
                // (MG 09-09-2026) — iframe-safe, без cookie.
                'clientTz' => ['client_tz' => true],
            ])
            ->header('Content-Security-Policy', self::FRAME_ANCESTORS);
    }

    /**
     * Полные расписания для виджета: активные видимые курсы с предстоящими
     * занятиями (не больше 10, по алфавиту), пост на группу потока.
     *
     * @return Collection<int, array{title: string, posts: list<FullSchedulePost>}>
     */
    private function fullSchedulePosts(): Collection
    {
        if (! config('features.schedule_full_post', false)) {
            return collect();
        }

        return Course::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereHas('schedules', fn ($q) => $q->where('start', '>=', now()))
            ->orderBy('title')
            ->limit(10)
            ->with('groups')
            ->get()
            ->map(fn ($course): array => [
                'title' => (string) $course->title,
                'posts' => FullSchedulePost::forCourse($course),
            ])
            ->filter(fn (array $row): bool => $row['posts'] !== []);
    }
}
