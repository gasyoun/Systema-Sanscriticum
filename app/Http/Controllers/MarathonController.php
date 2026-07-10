<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * H440 — 3-day diagnostic marathon «Консультация по онлайн-курсам ОРС»,
 * Phase 1: landing + capture. Reuses the Lead capture pattern from
 * LeadController (rate limit, dedup) rather than posting through it
 * directly — the marathon needs extra fields (track, quiz_goal) and a
 * different post-capture destination (the Day-0 quiz result), not
 * LeadController's generic "thank you" redirect.
 *
 * Phases 2-6 (drip engine, Day 1-2 async content delivery, paid-track
 * checkout, live consultation booking, warm-tail) are NOT in this slice —
 * see Uprava/handoffs/H440-*.md and the design doc it links for the full
 * build plan.
 */
class MarathonController extends Controller
{
    /** Content-free reuse of ShopController's quiz shape, day-0 scope only
     * (goal→route, not skill-grading — this cohort is all-zero, no deva). */
    public const QUIZ_GOALS = [
        'grammar' => 'Хочу читать тексты и понимать язык',
        'yoga' => 'Йога, мантры, рецитация',
        'philo' => 'Философия — Йога-сутры, Упанишады',
        'try' => 'Хочу попробовать — пока не знаю',
    ];

    public function show(): View
    {
        $landing = LandingPage::where('slug', config('marathon.landing_slug'))->first();

        return view('marathon.show', [
            'landing' => $landing,
            'quizGoals' => self::QUIZ_GOALS,
            'paidTrackPrice' => config('marathon.paid_track_price'),
            'couponAmount' => config('marathon.coupon_amount'),
            'hostName' => config('marathon.host_name'),
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $rlKey = 'marathon-register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'contact' => 'required|string',
            'email' => 'nullable|email',
            'social' => 'nullable|string|max:255',
            'track' => 'required|in:'.MarathonEnrollment::TRACK_FREE.','.MarathonEnrollment::TRACK_PAID,
            'quiz_goal' => 'required|in:'.implode(',', array_keys(self::QUIZ_GOALS)),
            'is_promo_agreed' => 'nullable',
        ]);

        $landing = LandingPage::where('slug', config('marathon.landing_slug'))->first();

        $leadData = [
            'name' => $validated['name'] ?? null,
            'contact' => $validated['contact'],
            'email' => $validated['email'] ?? null,
            'social' => $validated['social'] ?? null,
            'landing_page_id' => $landing?->id,
            'is_promo_agreed' => $request->has('is_promo_agreed'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        if (empty($leadData['email']) && filter_var($leadData['contact'], FILTER_VALIDATE_EMAIL)) {
            $leadData['email'] = $leadData['contact'];
        }

        // Dedup by email (or contact if no email) within the marathon landing
        // specifically — a lead who already registered for THIS marathon
        // resumes their existing enrollment rather than getting a second
        // day0_started_at clock (which would reset their personal drip day).
        [$dupColumn, $dupValue] = ! empty($leadData['email'])
            ? ['email', $leadData['email']]
            : ['contact', $leadData['contact']];

        $query = Lead::where($dupColumn, $dupValue);
        if ($landing) {
            $query->where('landing_page_id', $landing->id);
        }
        $existingLead = $query->first();

        if ($existingLead) {
            $enrollment = MarathonEnrollment::where('lead_id', $existingLead->id)->first();
            if ($enrollment) {
                return redirect()->route('marathon.show')->with('marathon_result', $enrollment->quiz_goal);
            }
            $lead = $existingLead;
        } else {
            $lead = Lead::create($leadData);
        }

        $enrollment = MarathonEnrollment::create([
            'lead_id' => $lead->id,
            'track' => $validated['track'],
            'quiz_goal' => $validated['quiz_goal'],
            'day0_started_at' => now(),
        ]);

        return redirect()->route('marathon.show')->with('marathon_result', $enrollment->quiz_goal);
    }
}
