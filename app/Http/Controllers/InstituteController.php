<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * H-institute — витрина «Институт исследования санскрита» (учебное крыло ОРС,
 * оператор ИП Гасунс): ДПП ПК «Санскрит» 72 ч, пилотная когорта 30 000 ₽
 * (MONETIZATION_PLAN_2026H2, решения MG 22–23-08-2026).
 *
 * Заявочная форма (не чекаут): оплата после собеседования по оферте ДПО.
 * Анти-срочность: никаких дедлайнов набора. Бренд-гварды: не «школа»/«академия».
 */
final class InstituteController extends Controller
{
    public function landing(Request $request): View
    {
        return view('institute.landing', [
            'applied' => $request->session()->get('institute_applied') === true,
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:255'],
            'experience' => ['nullable', 'string', 'max:2000'],
            'is_promo_agreed' => ['accepted'],
            // honeypot: скрытое поле должно остаться пустым
            'website' => ['prohibited'],
        ], [
            'is_promo_agreed.accepted' => 'Нужно согласие на обработку персональных данных.',
            'website.prohibited' => 'Заявка не принята.',
        ]);

        $experience = trim((string) ($validated['experience'] ?? ''));
        $contact = $experience !== ''
            ? $validated['contact']."\nРоль/опыт: ".$experience
            : $validated['contact'];

        $lead = Lead::create([
            'name' => $validated['name'],
            'contact' => $contact,
            'email' => filter_var($validated['contact'], FILTER_VALIDATE_EMAIL) ? $validated['contact'] : null,
            'is_promo_agreed' => true,
            'utm_source' => (string) $request->input('utm_source', 'institut'),
            'utm_medium' => (string) $request->input('utm_medium', 'organic'),
            'utm_campaign' => (string) $request->input('utm_campaign', 'a1_pk72_pilot'),
            'utm_content' => (string) $request->input('utm_content', ''),
            'utm_term' => (string) $request->input('utm_term', ''),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'referrer' => (string) $request->headers->get('referer'),
        ]);

        Log::info('institute: заявка ПК-72', ['lead_id' => $lead->id]);

        return redirect()
            ->route('institute.landing')
            ->with('institute_applied', true);
    }
}
