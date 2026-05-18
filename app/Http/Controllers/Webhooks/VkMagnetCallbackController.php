<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVkMagnetCallback;
use App\Models\MarketingSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VkMagnetCallbackController extends Controller
{
    public function handle(Request $request): Response
    {
        $payload = $request->json()->all();

        // VK при настройке Callback API ждёт строго plaintext с кодом подтверждения.
        if (($payload['type'] ?? '') === 'confirmation') {
            $code = (string) (MarketingSetting::first()?->vk_confirmation_code ?? '');

            return response($code, 200)->header('Content-Type', 'text/plain');
        }

        ProcessVkMagnetCallback::dispatch($payload);

        // VK требует plain "ok" (не JSON) — иначе считает callback упавшим.
        return response('ok', 200)->header('Content-Type', 'text/plain');
    }
}
