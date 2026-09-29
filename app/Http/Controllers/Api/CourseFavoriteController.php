<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseFavorite;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * H5134 — «Избранное» (сердечки): toggle + мой список.
 *
 * Тот же контур, что голос ждуна (MG 31-08-2026 / 01-09-2026): web-группа
 * (сессия + CSRF), auth обязателен — гость 401; флаг course_favorites OFF —
 * 404 (механизм не живёт раньше включения). Отдельно от голоса ждуна:
 * WaitlistVote не трогаем, писем нет, PII не логируем.
 */
class CourseFavoriteController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        if (! config('features.course_favorites', false)) {
            abort(404);
        }

        // Гость — 401 (кнопка на витрине ведёт на вход).
        $user = $request->user('web') ?? $request->user();
        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'error' => 'auth_required'], 401);
        }

        $data = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'waitlist_slug' => ['nullable', 'string', 'max:180', 'exists:course_waitlist_items,slug'],
        ]);

        // Ровно одна цель: сердечко на курсе ИЛИ на карточке ждуна без курса.
        if (array_key_exists('course_id', $data) === array_key_exists('waitlist_slug', $data)) {
            return response()->json([
                'ok' => false,
                'error' => 'exactly_one_target_required',
            ], 422);
        }

        $match = [
            'user_id' => $user->getKey(),
            'course_id' => $data['course_id'] ?? null,
            'waitlist_slug' => $data['waitlist_slug'] ?? null,
        ];

        // Идемпотентный toggle: повторный клик снимает сердечко.
        $existing = CourseFavorite::query()->where($match)->first();
        if ($existing !== null) {
            $existing->delete();
            $favorited = false;
        } else {
            CourseFavorite::create($match);
            $favorited = true;
        }

        return response()->json([
            'ok' => true,
            'favorited' => $favorited,
        ]);
    }

    /** Мой список сердечек (кабинет/JS): курсы + анонсы ждуна без курса. */
    public function index(): JsonResponse
    {
        if (! config('features.course_favorites', false)) {
            abort(404);
        }

        $user = request()->user('web') ?? request()->user();
        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'error' => 'auth_required'], 401);
        }

        $favorites = CourseFavorite::query()
            ->where('user_id', $user->getKey())
            ->with(['course:id,slug,title,is_visible'])
            ->orderByDesc('id')
            ->get();

        // Ссылка живая только у видимого курса; у waitlist-сердечка —
        // страница ждуна (карточка там).
        $items = $favorites->map(fn (CourseFavorite $favorite): array => [
            'key' => $favorite->heartKey(),
            'course_id' => $favorite->course_id,
            'waitlist_slug' => $favorite->waitlist_slug,
            'title' => $favorite->course?->title
                ?? CourseWaitlistItem::query()->where('slug', $favorite->waitlist_slug)->value('course_title')
                ?? $favorite->waitlist_slug,
            'url' => $favorite->course && $favorite->course->is_visible
                ? route('shop.course.show', $favorite->course->slug)
                : ($favorite->waitlist_slug ? route('shop.waitlist') : null),
            'created_at' => $favorite->created_at?->toIso8601String(),
        ])->all();

        return response()->json(['ok' => true, 'data' => $items]);
    }
}
