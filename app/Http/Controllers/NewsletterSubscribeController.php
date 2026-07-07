<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MagicLinkToken;
use App\Services\NewsletterSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Подписка на рассылку (H324): email-only → кабинетный User + magic-link + полка
 * магнитов. Всё под фича-флагом newsletter_subscribe; при OFF маршруты 404.
 */
class NewsletterSubscribeController extends Controller
{
    private function abortIfDisabled(): void
    {
        abort_unless(config('features.newsletter_subscribe'), 404);
    }

    /** Приём подписки. Ответ НЕ раскрывает, был ли уже такой аккаунт. */
    public function store(Request $request, NewsletterSubscriptionService $service): RedirectResponse
    {
        $this->abortIfDisabled();

        // Троттлинг как у lead-формы: 1 запрос / 5 сек / IP.
        $rlKey = 'newsletter-subscribe:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            // Явное согласие на рассылку — как чекбокс промо на лендинге.
            'is_promo_agreed' => ['accepted'],
        ]);

        $service->subscribe($validated['email'], [
            'is_promo_agreed' => true,
            'utm_source' => $request->input('utm_source'),
            'utm_medium' => $request->input('utm_medium'),
            'utm_campaign' => $request->input('utm_campaign'),
            'utm_content' => $request->input('utm_content'),
            'utm_term' => $request->input('utm_term'),
            'click_id' => $request->input('click_id'),
            'referrer' => $request->input('referrer') ?: $request->headers->get('referer'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Единый ответ независимо от того, новый это аккаунт или нет (анти-enumeration).
        return back()->with('newsletter_subscribed', true);
    }

    /**
     * Вход по одноразовой magic-ссылке. Валидна ровно один раз, короткий TTL,
     * hashed-at-rest. Невалид/протух/использован → 404 (без enumeration).
     */
    public function magic(string $token): RedirectResponse
    {
        $this->abortIfDisabled();

        $link = MagicLinkToken::findActive($token);

        abort_if($link === null, 404);

        // Атомарно гасим — проигравший гонку/replay получит 404.
        abort_unless($link->consume(), 404);

        Auth::login($link->user, remember: true);

        return redirect()->route('student.dashboard')
            ->with('status', 'Добро пожаловать! Ваши бонусы — на полке подписчика ниже.');
    }
}
