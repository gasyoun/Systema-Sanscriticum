<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\InstituteDonation;
use App\Models\Lead;
use App\Models\User;
use App\Services\Payments\TochkaPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * N2/N3: страница «Меценаты» с онлайн-формой (за флагом) и реквизитами.
     */
    public function mecenaty(Request $request): View
    {
        return view('institute.mecenaty', [
            'donateOnline' => config('features.institute_donate_online'),
            'presets' => config('institute.donate_presets', []),
            'minAmount' => (int) config('institute.donate_min', 100),
            'donated' => $request->query('donate') === 'ok',
            'donateFailed' => $request->query('donate') === 'fail',
        ]);
    }

    /**
     * N2: приём добровольного пожертвования свободной суммы через Точку.
     * Ст. 582 ГК, без встречного пакета благ; успех вебхука меняет только
     * строку institute_donations — платёжный success-путь доступов не трогается.
     */
    public function donate(Request $request, TochkaPaymentService $tochka): RedirectResponse
    {
        if (! config('features.institute_donate_online')) {
            abort(404);
        }

        $validated = $request->validate([
            // Свободная сумма; пресеты — только подсказка в форме.
            'amount' => ['required', 'numeric', 'min:'.config('institute.donate_min', 100), 'max:1000000'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'publish_name' => ['nullable', 'boolean'],
            // Сумма в реестре — только по отдельной просьбе конкретного человека
            // и только вместе с опубликованным именем.
            'show_amount' => ['nullable', 'boolean'],
            // honeypot: скрытое поле должно остаться пустым
            'website' => ['prohibited'],
        ], [
            'amount.required' => 'Укажите сумму пожертвования.',
            'email.required' => 'Для чека нужен адрес электронной почты.',
            'website.prohibited' => 'Заявка не принята.',
        ]);

        $publishName = (bool) ($validated['publish_name'] ?? false);
        $showAmount = $publishName && (bool) ($validated['show_amount'] ?? false);

        $donation = InstituteDonation::create([
            'uuid' => (string) Str::uuid(),
            'amount' => round((float) $validated['amount'], 2),
            'status' => InstituteDonation::STATUS_PENDING,
            'donor_name' => $publishName
                ? trim((string) ($validated['name'] ?? '')) ?: null
                : null,
            'email' => $validated['email'],
            'publish_name' => $publishName,
            'show_amount' => $showAmount,
            'utm_source' => (string) $request->input('utm_source', 'mecenaty'),
            'utm_medium' => (string) $request->input('utm_medium', 'organic'),
            'utm_campaign' => (string) $request->input('utm_campaign', ''),
            'utm_content' => (string) $request->input('utm_content', ''),
            'utm_term' => (string) $request->input('utm_term', ''),
        ]);

        try {
            // Призрак-получатель чека без записи в users: сервис читает только атрибуты.
            $ghost = new User([
                'name' => $donation->donor_name ?? 'Меценат',
                'email' => $donation->email,
            ]);

            $response = $tochka->createPaymentWithReceipt(
                user: $ghost,
                amount: (float) $donation->amount,
                purpose: "Добровольное пожертвование Институту исследования санскрита №D{$donation->id}",
                itemName: 'Добровольное пожертвование',
                paymentObject: 'service',
                redirectUrl: route('institute.mecenaty', ['donate' => 'ok']),
                failRedirectUrl: route('institute.mecenaty', ['donate' => 'fail']),
            );
        } catch (ConnectionException $e) {
            $donation->update(['status' => InstituteDonation::STATUS_FAILED]);
            Log::error('Tochka недоступна (institute_donation)', [
                'donation_id' => $donation->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
        }

        if ($response->successful() && isset($response['Data']['paymentLink'])) {
            $donation->update(['tochka_link_id' => $response['Data']['paymentLinkId']]);

            return redirect()->away($response['Data']['paymentLink']);
        }

        $donation->update(['status' => InstituteDonation::STATUS_FAILED]);
        Log::error('Ошибка оплаты пожертвования (institute_donation)', [
            'donation_id' => $donation->id,
            'status' => $response->status(),
        ]);

        return back()->with('error', 'Сервис оплаты временно недоступен. Попробуйте позже.');
    }

    /**
     * N3: публичный реестр благодарностей — имена давших согласие;
     * сумма видна только там, где человек попросил её указать.
     */
    public function gratitudeRegistry(): View
    {
        return view('institute.gratitude', [
            'donations' => config('features.institute_donate_online')
                ? InstituteDonation::query()->publicRegistry()->get()
                : collect(),
        ]);
    }
}
