<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\StorefrontAnalyticsEvent;
use App\Services\Activity\StorefrontAnalytics;
use App\Support\FlagshipExperiments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * R12 next-step click logger (H2762). GET so guests do not need CSRF.
 * Destination is resolved server-side — the client cannot send a URL.
 */
class StorefrontAnalyticsController extends Controller
{
    public function nextStep(Request $request, string $target): RedirectResponse
    {
        if (! in_array($target, FlagshipExperiments::TARGETS, true)) {
            abort(404);
        }

        $from = $this->fromCourse($request);

        if (FlagshipExperiments::nextStepEnabled()) {
            app(StorefrontAnalytics::class)->record(
                event: StorefrontAnalyticsEvent::NEXT_STEP_CLICK,
                experiment: StorefrontAnalyticsEvent::EXPERIMENT_NEXT_STEP,
                request: $request,
                course: $from,
                target: $target,
            );
        }

        return redirect()->away(FlagshipExperiments::resolveTargetUrl($target));
    }

    private function fromCourse(Request $request): ?Course
    {
        $id = (int) $request->query('from', 0);
        if ($id < 1) {
            return null;
        }

        return Course::query()->find($id);
    }
}
