<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Anons\AnonsMetricsService;
use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\PublicationKey;
use App\Services\Anons\PublicationManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * H5049 R10: компактный аутентифицированный API публикации.
 * draft/validate/preview/publish/schedule/status/metrics/rollback —
 * CLI (anons:*) и HTTP зовут ОДИН и тот же AnonsPublishingService.
 *
 * Роутинг: routes/api.php, auth:sanctum (personal access tokens).
 * Rollback удаляет ТОЛЬКО свои recorded remote id (bounded, R10).
 */
final class AnonsApiController extends Controller
{
    /** POST /api/anons — draft: принять манифест, вернуть ключ/хэш/ошибки валидации. */
    public function draft(Request $request): JsonResponse
    {
        $manifest = $this->manifest($request);
        $errors = app(AnonsPublishingService::class)->validate($manifest);

        return response()->json([
            'publication_key' => PublicationKey::fromManifest($manifest),
            'manifest_hash' => $manifest->hash(),
            'valid' => $errors === [],
            'errors' => $errors,
        ]);
    }

    /** POST /api/anons/preview — рендер без публикации (R3). */
    public function preview(Request $request): JsonResponse
    {
        try {
            $preview = app(AnonsPublishingService::class)->preview($this->manifest($request));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    /** POST /api/anons/publish — идемпотентная публикация (R2/R8/R12/R14). */
    public function publish(Request $request): JsonResponse
    {
        try {
            $publication = app(AnonsPublishingService::class)
                ->publish($this->manifest($request), promote: (bool) $request->boolean('promote'));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'publication_key' => $publication->publication_key,
            'status' => $publication->status,
            'test_mode' => $publication->test_mode,
            'status_detail' => app(AnonsPublishingService::class)->status($publication->publication_key),
        ]);
    }

    /** GET /api/anons/{key} — статус по ключу. */
    public function status(string $key): JsonResponse
    {
        $status = app(AnonsPublishingService::class)->status($key);
        if ($status === null) {
            return response()->json(['error' => "unknown publication key: {$key}"], 404);
        }

        return response()->json($status);
    }

    /** POST /api/anons/{key}/metrics — собрать наблюдения (R6/R7). */
    public function metrics(string $key): JsonResponse
    {
        return response()->json(app(AnonsMetricsService::class)->collect($key));
    }

    /** POST /api/anons/{key}/rollback — bounded rollback (R10). */
    public function rollback(string $key): JsonResponse
    {
        try {
            $deleted = app(AnonsPublishingService::class)->rollback($key);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['deleted' => $deleted]);
    }

    private function manifest(Request $request): PublicationManifest
    {
        if (is_array($request->input('manifest'))) {
            return PublicationManifest::fromArray($request->input('manifest'));
        }

        $path = (string) $request->input('manifest_path', '');
        if ($path === '') {
            throw new RuntimeException('Provide either manifest{} object or manifest_path.');
        }

        return PublicationManifest::fromFile($path);
    }
}
