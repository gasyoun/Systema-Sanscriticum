<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\Payment;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\Messaging\DeliveryChannelManager;
use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\Payments\TochkaPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * H440 — 3-day diagnostic marathon «Консультация по онлайн-курсам ОРС»,
 * Phase 1: landing + capture. Reuses the Lead capture pattern from
 * LeadController (rate limit, dedup) rather than posting through it
 * directly — the marathon needs extra fields (track, quiz_goal) and a
 * different post-capture destination (the Day-0 quiz result), not
 * LeadController's generic "thank you" redirect.
 *
 * Phase 2 (H464): attaches a magnet_token/magnet_channel='telegram' to the
 * Lead at capture time (same generic deep-link mechanism LeadController
 * uses for lead-magnet files — reused as-is, not landing-magnet-specific)
 * so `marathon:deliver-due` (H464) can find enrollees by `telegram_chat_id`
 * once they start the bot.
 *
 * Phase 4 (H471): `pay()` — ₽500 «с проверкой» track checkout via the
 * existing Tochka gateway, mirrors TrialController::create() exactly
 * (bespoke Payment, no Tariff row). Promo-code issuance and flagship-course
 * credit are explicit follow-ons, not built here — see H471.
 *
 * Phase 3b (H483): `day()`/`completeDay()` — genuine tap-choice recognition
 * pages for Day 1/2 (reuses ShopController::start()'s Alpine quiz pattern,
 * keyed by the lead's existing magnet_token), plus Day-3 question capture
 * (`day2_question`). No webhook/bot changes — see H483 "Why this scope".
 *
 * Phases 5/6 (live consultation booking, warm-tail) are NOT in this slice —
 * see Uprava/handoffs/H440-*.md.
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
                $this->attachTelegramMagnet($existingLead);

                return redirect()->route('marathon.show')
                    ->with('marathon_result', $enrollment->quiz_goal)
                    ->with('marathon_telegram_link', $this->deepLink($existingLead))
                    ->with('marathon_track', $enrollment->track)
                    ->with('marathon_paid', $enrollment->isPaidConfirmed())
                    ->with('marathon_contact', $existingLead->contact);
            }
            $lead = $existingLead;
        } else {
            $lead = Lead::create($leadData);
        }

        // H445 Phase 1 — this route only ever serves the August all-zero
        // landing today (`cohort` defaults to `zero` at the DB level too);
        // a January-cohort landing + route is its own future phase (see
        // Uprava/handoffs/H445-*.md), wired through once that landing's
        // slug/copy is actually decided. Explicit here, not left to the
        // column default, so the intent reads at the call site.
        $enrollment = MarathonEnrollment::create([
            'lead_id' => $lead->id,
            'track' => $validated['track'],
            'cohort' => MarathonEnrollment::COHORT_ZERO,
            'quiz_goal' => $validated['quiz_goal'],
            'day0_started_at' => now(),
        ]);

        $this->attachTelegramMagnet($lead);

        return redirect()->route('marathon.show')
            ->with('marathon_result', $enrollment->quiz_goal)
            ->with('marathon_telegram_link', $this->deepLink($lead))
            ->with('marathon_track', $enrollment->track)
            ->with('marathon_paid', $enrollment->isPaidConfirmed())
            ->with('marathon_contact', $lead->contact);
    }

    /**
     * H471 — ₽500 «с проверкой» track checkout. Mirrors TrialController::create()
     * (bespoke Payment, no Tariff row, DB::transaction then Tochka after commit).
     * Idempotent: an already-paid enrollment redirects back without charging twice.
     */
    public function pay(Request $request, TochkaPaymentService $tochka): RedirectResponse
    {
        $rlKey = 'marathon-pay:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $validated = $request->validate([
            'contact' => 'required|string',
            'email' => 'required|email',
        ]);

        $landing = LandingPage::where('slug', config('marathon.landing_slug'))->first();

        $leadQuery = Lead::where('contact', $validated['contact']);
        if ($landing) {
            $leadQuery->where('landing_page_id', $landing->id);
        }
        $lead = $leadQuery->first();

        if (! $lead) {
            return back()->with('error', 'Сначала зарегистрируйтесь на марафон — контакт не найден.');
        }

        $enrollment = MarathonEnrollment::where('lead_id', $lead->id)->first();

        abort_unless(
            $enrollment && $enrollment->isPaidTrack(),
            403,
            'Трек «с проверкой» не выбран при регистрации на марафон.'
        );

        // Идемпотентность: уже оплачено — не берём деньги повторно.
        if ($enrollment->isPaidConfirmed()) {
            return redirect()->route('marathon.show')
                ->with('marathon_result', $enrollment->quiz_goal)
                ->with('marathon_track', $enrollment->track)
                ->with('marathon_paid', true);
        }

        try {
            $user = $this->resolveUserForCheckout($validated['email'], $lead);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $amount = (float) config('marathon.paid_track_price');

        $payment = DB::transaction(function () use ($user, $lead, $amount): Payment {
            return Payment::create([
                'user_id' => $user->id,
                'lead_id' => $lead->id,
                'course_id' => null,
                'amount' => $amount,
                'tariff' => 'marathon_paid',
                'status' => 'pending',
            ]);
        });

        // Purpose должен включать «Заказ №{id}» — иначе вебхук Tochka не найдёт платёж.
        $purpose = 'Заказ №'.$payment->id.' | Марафон «с проверкой»';

        try {
            $response = $tochka->createPaymentWithReceipt(
                user: $user,
                amount: $amount,
                purpose: $purpose,
                itemName: 'Марафон «Консультация по онлайн-курсам ОРС» — трек «с проверкой»',
            );
        } catch (ConnectionException $e) {
            $payment->update(['status' => 'failed']);

            Log::error('Tochka недоступна (marathon_paid)', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        }

        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $payment->update(['transaction_id' => $response['Data']['paymentLinkId']]);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $payment->update(['status' => 'failed']);

        Log::error('Ошибка Точка Эквайринг (marathon_paid)', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * H483 — Day 1/2 tap-choice recognition page. Keyed by the lead's
     * existing magnet_token (H446/H464), no new token needed. 404 for an
     * unknown token or a day that doesn't match an enrolled lead.
     */
    public function day(int $day, string $token): View
    {
        $lead = Lead::where('magnet_token', $token)->firstOrFail();
        $enrollment = MarathonEnrollment::where('lead_id', $lead->id)->firstOrFail();

        // H445 Phase 1 — per-cohort content (falls back to the shared `zero`
        // default when the enrollment's cohort has no day{N}_quiz override).
        $quiz = $enrollment->content("day{$day}_quiz");
        abort_if($quiz === null, 404);

        return view("marathon.day{$day}", [
            'quiz' => $quiz,
            'day' => $day,
            'token' => $token,
            'enrollment' => $enrollment,
        ]);
    }

    /**
     * H483 — marks the tap sequence finished (day{N}_engaged_at, distinct
     * from day{N}_completed_at which means "delivered" — see H464/H471,
     * do NOT conflate). Day 2 optionally persists the Day-3 consultation
     * question (day2_question, column exists since H446, unused until now).
     */
    public function completeDay(int $day, string $token, Request $request): RedirectResponse
    {
        $lead = Lead::where('magnet_token', $token)->firstOrFail();
        $enrollment = MarathonEnrollment::where('lead_id', $lead->id)->firstOrFail();

        $updates = [];

        if ($day === 1 && $enrollment->day1_engaged_at === null) {
            $updates['day1_engaged_at'] = now();
        }

        if ($day === 2) {
            if ($enrollment->day2_engaged_at === null) {
                $updates['day2_engaged_at'] = now();
            }

            $question = trim((string) $request->input('question', ''));
            if ($question !== '' && $enrollment->day2_question === null) {
                $updates['day2_question'] = mb_substr($question, 0, 2000);
            }
        }

        if ($updates !== []) {
            $enrollment->update($updates);
        }

        return redirect()->route('marathon.day', ['day' => $day, 'token' => $token])
            ->with('marathon_day_done', true);
    }

    /**
     * Залогиненный — текущий. Гость с НОВЫМ email — создаём аккаунт и логиним
     * (без пароля, без surname/city — марафонский захват их не собирает).
     * Гость с СУЩЕСТВУЮЩИМ email — отказ (защита от account takeover, как в
     * TrialController::resolveUser()).
     */
    private function resolveUserForCheckout(string $email, Lead $lead): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($email))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и оплатите оттуда.',
            ]);
        }

        $user = User::create([
            'email' => $email,
            'name' => $lead->name ?: 'Участник марафона',
            'password' => Hash::make(Str::random(12)),
        ]);

        app(AttributionService::class)->applyToNewUser($user);

        auth()->login($user);

        return $user;
    }

    /**
     * Attaches a magnet_token/magnet_channel='telegram' to the lead if it
     * doesn't already have one — the same generic mechanism LeadController
     * uses for lead-magnet files (Lead::magnet_token is not landing-magnet-
     * specific). Once the lead starts the bot with this token, the existing
     * unmodified ProcessTelegramMagnetUpdate webhook path sets
     * telegram_chat_id; marathon:deliver-due (H464) then finds them by it.
     */
    private function attachTelegramMagnet(Lead $lead): void
    {
        if ($lead->magnet_token) {
            return;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $lead->update([
                    'magnet_token' => Str::random(12),
                    'magnet_channel' => 'telegram',
                ]);

                return;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException("Не удалось сгенерировать уникальный magnet_token для Lead #{$lead->id}");
    }

    private function deepLink(Lead $lead): ?string
    {
        if (! $lead->magnet_token) {
            return null;
        }

        $channel = app(DeliveryChannelManager::class)->get('telegram');

        return $channel instanceof TelegramDeliveryChannel
            ? $channel->buildDeepLink($lead->magnet_token)
            : null;
    }
}
