<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicWaitlistResource;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use App\Models\WaitlistVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Публичный read-only фид «Списка ожидания» (MG ruling 31-08-2026, волна 1).
 *
 * Граница — {@see PublicWaitlistResource} (allowlist, никаких id/PII).
 * Голосование — ОТДЕЛЬНЫЙ эндпоинт (auth:sanctum, волна 3); фид только
 * показывает прогресс голосов. Ответ кэшируется 2 минуты.
 */
class PublicWaitlistController extends Controller
{
    private const CACHE_TTL_MINUTES = 5;

    public function index(): JsonResponse
    {
        $data = Cache::remember(
            'public_waitlist:v1',
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->buildFeed(),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Голос из кабинета (только зарегистрированные; идемпотентный — повторный
     * клик не дублирует). Флаг waitlist_voting ON, иначе 404. Прогресс наружу.
     */
    public function vote(Request $request): JsonResponse
    {
        if (! config('features.waitlist_voting', false)) {
            abort(404);
        }

        // Только зарегистрированные в кабинете (MG 31-08-2026). Гость — 401.
        // Из web-группы (/online/zhdun/vote, 01-09-2026) сессия стартует в
        // мидлвари, user('web') резолвится; из api — как раньше.
        $user = $request->user('web') ?? $request->user();
        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'error' => 'auth_required'], 401);
        }

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:180'],
        ]);

        $item = CourseWaitlistItem::query()
            ->where('slug', $data['slug'])
            ->where('is_listed', true)
            ->first();

        if ($item === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        // 1 голос с юзера на строку: firstOrCreate — повтор не дублирует.
        WaitlistVote::firstOrCreate([
            'course_waitlist_item_id' => $item->getKey(),
            'user_id' => $user->getKey(),
        ]);

        // Кэш фида сбрасываем — прогресс на карточках должен обновиться сразу.
        Cache::forget('public_waitlist:v1');

        return response()->json([
            'ok' => true,
            'votes' => $item->votesCount(),
            'min_payers' => $item->min_payers,
            'threshold_met' => $item->hasThreshold(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildFeed(): array
    {
        $items = CourseWaitlistItem::query()
            ->where('is_listed', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->withCount('votes')
            ->get();

        return PublicWaitlistResource::collection($items)->resolve();
    }
}
