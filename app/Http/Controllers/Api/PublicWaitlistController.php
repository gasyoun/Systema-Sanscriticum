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

    /** Голос гостя, отложенный до входа: ['slug' => …, 'slot_preference' => …]. */
    public const PENDING_VOTE_SESSION_KEY = 'waitlist.pending_vote';

    /** Флеш «голос учтён» — зелёное уведомление на /online/zhdun. */
    public const VOTED_FLASH_KEY = 'waitlist_voted';

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

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:180'],
            // H4206: пожелание времени слота; повторный голос обновляет его.
            'slot_preference' => ['nullable', 'string', 'in:'.implode(',', array_keys(WaitlistVote::SLOT_PREFERENCES))],
        ]);

        // Только зарегистрированные в кабинете (MG 31-08-2026). Гость — 401.
        // Из web-группы (/online/zhdun/vote, 01-09-2026) сессия стартует в
        // мидлвари, user('web') резолвится; из api — как раньше.
        $user = $request->user('web') ?? $request->user();
        if (! $user instanceof User) {
            // Гость с витрины: голос откладываем в сессию — его засчитает
            // CastPendingWaitlistVote при входе/регистрации, а после входа
            // вернём на /online/zhdun (url.intended).
            if ($request->hasSession()) {
                $request->session()->put(self::PENDING_VOTE_SESSION_KEY, [
                    'slug' => $data['slug'],
                    'slot_preference' => $data['slot_preference'] ?? null,
                ]);
                $request->session()->put('url.intended', route('shop.waitlist'));
            }

            return response()->json(['ok' => false, 'error' => 'auth_required'], 401);
        }

        $item = CourseWaitlistItem::query()
            ->where('slug', $data['slug'])
            ->where('is_listed', true)
            ->first();

        if ($item === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $item->castVoteBy($user, $data['slot_preference'] ?? null);

        // Страница перезагрузится после ответа — там покажем «Спасибо, ваш голос учтён!».
        if ($request->hasSession()) {
            $request->session()->flash(self::VOTED_FLASH_KEY, true);
        }

        return response()->json([
            'ok' => true,
            'votes' => $item->votesCount(),
            'min_payers' => $item->min_payers,
            'threshold_met' => $item->hasThreshold(),
        ]);
    }

    /**
     * Отзыв своего голоса (MG 01-09-2026, «передумал»): удаляет голос юзера.
     * Идемпотентный — отмена без голоса не ошибка.
     */
    public function unvote(Request $request): JsonResponse
    {
        if (! config('features.waitlist_voting', false)) {
            abort(404);
        }

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

        $item->votes()->where('user_id', $user->getKey())->delete();

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
