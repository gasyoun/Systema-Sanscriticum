<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VkUserToken;
use App\Services\Content\VkTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Действующий VK ID user-токен для n8n (21-11-2026).
 *
 * Токен живёт ~час и ротируется вместе с refresh_token, поэтому владелец пары
 * один — прод (VkTokenManager). n8n сам НЕ обновляет: он просит у прода токен
 * прямо перед вызовом VK API. Здесь же, под тем же Cache::lock, делаем
 * refreshIfNeeded() — если до истечения меньше 35 минут, n8n получит свежий.
 * refresh_token наружу не отдаём никогда.
 */
class VkTokenController extends Controller
{
    public function show(VkTokenManager $manager): JsonResponse
    {
        $manager->refreshIfNeeded();

        $token = VkUserToken::current();
        $access = VkUserToken::activeAccessToken();
        if ($token === null || $access === null) {
            Log::warning('VkTokenController: действующего VK-токена нет');

            return response()->json(['error' => 'no_active_token'], 503);
        }

        Log::info('VkTokenController: токен выдан n8n', [
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);

        return response()->json([
            'access_token' => $access,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'scope' => $token->scope,
            'has_video' => $token->hasScope('video'),
        ])->header('Cache-Control', 'no-store');
    }
}
