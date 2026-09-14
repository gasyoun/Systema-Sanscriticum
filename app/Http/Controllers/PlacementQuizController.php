<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PedagogyRung;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * F2 rung-placement diagnostic step (H4818, R2609-01) — same tree/Alpine
 * mechanic as the onramp quiz (ShopController::start), extended with a
 * rung-coded result instead of a course recommendation. Flag-gated
 * (features.f2_placement_quiz), OFF by default -> both routes 404.
 *
 * store() only writes to the SESSION. The Deal-side write
 * (TrialBookingService::recordPlacementRung) happens later, from
 * TrialController::create() (paid-trial form, same-origin, reads the
 * session) or from the public widget's explicit `placement_rung` field
 * (PublicTrialBookController, cross-origin, no session). This controller
 * never touches Payment/Deal/Tochka.
 */
class PlacementQuizController extends Controller
{
    public function show(): View
    {
        abort_unless(config('features.f2_placement_quiz'), 404);

        return view('trial.placement-quiz', [
            'quiz' => config('placement_quiz'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(config('features.f2_placement_quiz'), 404);

        $data = $request->validate([
            'rung' => ['required', 'string', Rule::in(PedagogyRung::values())],
        ]);

        session(['placement_rung' => $data['rung']]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
