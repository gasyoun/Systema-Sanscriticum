<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Srs\SrsOnboardingFromGames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * H1680 — POST /api/games/srs-onboarding-import. Auth-only: the cabinet
 * dashboard posts back the SAME anon_id telemetry.js already keeps in
 * localStorage (once per browser, deduped client-side), and this seeds the
 * onboarding-from-games SRS deck from that guest's own free-play history.
 * No-op (`imported: 0`) while srs.enabled is OFF or the guest never played
 * — see {@see SrsOnboardingFromGames}.
 */
class GamesSrsOnboardingController extends Controller
{
    public function store(Request $request, SrsOnboardingFromGames $service): JsonResponse
    {
        $imported = $service->importForUser($request->user(), $this->anonId($request));

        return response()->json(['imported' => $imported]);
    }

    /** Same sanitization as GameTelemetryController::anonId() — [A-Za-z0-9]{0,32}. */
    private function anonId(Request $request): ?string
    {
        $raw = (string) $request->input('anon_id', '');
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, 32);
    }
}
