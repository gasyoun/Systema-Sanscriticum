<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMaxMagnetUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MaxMagnetWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        ProcessMaxMagnetUpdate::dispatch($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
