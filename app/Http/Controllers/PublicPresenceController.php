<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ResolveVisitorPresenceGeoJob;
use App\Models\SupportVisitorPresence;
use App\Services\Support\SupportConversationManager;
use App\Support\GuestChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Presence-beacon проактивного монитора посетителей (H1197, Jivo-паритет Pillar 2).
 *
 * Виджет витрины периодически (config support_presence.beacon_interval_seconds)
 * шлёт сюда {page}. Сервер апсертит эфемерную строку support_visitor_presences
 * по guest_token посетителя (тот же session-токен, что владеет гостевым тредом
 * H536) — куратор в странице «Посетители онлайн» видит живой список и может
 * написать первым. Ответ возвращает conversation_id: если куратор УЖЕ написал
 * молчащему посетителю (проактив создал тред), виджет узнаёт id, подтягивает
 * историю и подписывается на живой канал — так проактив «долетает».
 *
 * Приватность/152-ФЗ: работает ТОЛЬКО за флагом features.support_visitor_presence
 * (по умолчанию OFF); при выключенном флаге строки не пишутся вовсе. Тело запроса
 * не логируется; IP наружу куратору не отдаётся (только город). Гость НИКОГДА не
 * резолвится в строку `users` — presence не идентификационный слой.
 */
class PublicPresenceController extends Controller
{
    public function ping(Request $request, SupportConversationManager $conversations): JsonResponse
    {
        // Deploy-рубильник: пока флаг OFF — presence отключён, ничего не пишем,
        // виджет по `enabled:false` прекращает beacon.
        if (! config('features.support_visitor_presence')) {
            return response()->json(['ok' => true, 'enabled' => false]);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'string', 'max:2048'],
        ]);

        $user = $request->user();
        $guestToken = GuestChat::token();
        $ip = (string) $request->ip();
        $referrer = $request->headers->get('referer');
        $now = now();

        $presence = SupportVisitorPresence::firstOrNew(['guest_token' => $guestToken]);
        $wasNew = ! $presence->exists;

        $presence->fill([
            'user_id' => $user?->id,
            'visitor_ip' => $ip !== '' ? $ip : null,
            'current_url' => isset($validated['page']) ? mb_substr($validated['page'], 0, 2048) : $presence->current_url,
            'referrer' => $referrer !== null ? mb_substr($referrer, 0, 2048) : $presence->referrer,
            'last_seen_at' => $now,
        ]);

        if ($wasNew) {
            $presence->first_seen_at = $now;
        }

        $presence->save();

        // Гео резолвим ОДИН раз при создании строки (не на каждый beacon) и только
        // за флагом support_visitor_geo — тем же асинхронным резолвером, что и S1.
        if ($wasNew && $ip !== '' && config('features.support_visitor_geo')) {
            ResolveVisitorPresenceGeoJob::dispatch($presence->id, $ip, [
                'city' => $request->headers->get('CF-IPCity'),
                'region' => $request->headers->get('CF-Region'),
                'country' => $request->headers->get('CF-IPCountry'),
            ]);
        }

        // Есть ли у посетителя тред? Если куратор написал первым — тред уже создан,
        // и виджет по этому id подпишется и раскроется (проактив долетает до молчащего).
        $thread = $user
            ? $conversations->currentFor($user)
            : $conversations->currentForGuest($guestToken);

        return response()->json([
            'ok' => true,
            'enabled' => true,
            'conversation_id' => $thread?->id,
        ]);
    }
}
