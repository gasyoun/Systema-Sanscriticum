<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Публичный лендинг партнёрской программы + приём заявок. Все условия и цифры
 * показываются прямо на странице (config/partner.php). Заявка создаёт партнёра
 * в статусе «на модерации» — код и ссылку показываем сразу, но атрибуция
 * клиентов начинает работать только после активации в админке.
 */
class PartnerController extends Controller
{
    /** 404, когда программа выключена — публичной страницы просто нет. */
    private function ensureEnabled(): void
    {
        abort_unless((bool) config('partner.enabled', false), 404);
    }

    public function landing(Request $request)
    {
        $this->ensureEnabled();

        return view('partners.landing', [
            'reward' => (float) config('partner.reward_amount', 1000),
            'fromBot' => $request->query('src') === 'bot',
        ]);
    }

    /**
     * Чистая (без «?») партнёрская ссылка /partner/{code}: кладёт код в сессию
     * (last-touch), привязывает залогиненного клиента и уводит на главную —
     * ссылку удобно шарить, без UTM-хвоста в адресной строке.
     */
    public function track(Request $request, string $code): RedirectResponse
    {
        $this->ensureEnabled();

        $code = trim($code);
        if ($code !== '') {
            if (! $request->session()->has('pref')) {
                $request->session()->put('pref', $code);
            }
            if ($request->user()) {
                app(\App\Services\PartnerService::class)->attachPartner($request->user(), $code);
            }
        }

        return redirect('/');
    }

    public function register(Request $request): RedirectResponse
    {
        $this->ensureEnabled();

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'telegram_username' => ['nullable', 'string', 'max:100'],
            'payout_details' => ['nullable', 'string', 'max:500'],
        ])->after(function ($validator) use ($request): void {
            // Нужен хотя бы один канал связи, иначе партнёра не с кем связать.
            if (blank($request->input('email'))
                && blank($request->input('phone'))
                && blank($request->input('telegram_username'))) {
                $validator->errors()->add('email', 'Укажите хотя бы один контакт: email, телефон или Telegram.');
            }
        })->validate();

        $tg = trim((string) ($data['telegram_username'] ?? ''));
        if ($tg !== '' && ! str_starts_with($tg, '@')) {
            $tg = '@'.$tg;
        }

        $partner = Partner::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'telegram_username' => $tg ?: null,
            'payout_details' => $data['payout_details'] ?? null,
            'code' => Partner::generateCode(),
            'status' => Partner::STATUS_PENDING,
        ]);

        return redirect()
            ->route('partners.registered', ['code' => $partner->code])
            ->with('partner_just_registered', true);
    }

    public function registered(Request $request, string $code)
    {
        $this->ensureEnabled();

        $partner = Partner::where('code', $code)->firstOrFail();

        return view('partners.registered', [
            'partner' => $partner,
            'reward' => $partner->rewardAmount(),
            'justRegistered' => (bool) $request->session()->get('partner_just_registered', false),
        ]);
    }
}
