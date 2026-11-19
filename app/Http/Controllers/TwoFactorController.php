<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\Roles;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * H6352-r5 (рулинг 7 грилла 10-10): подключение и проверка второго фактора
 * для админ-ролей. Страницы намеренно вне Filament — гейт
 * {@see RequireTwoFactor} стоит в группе `web` и в стеке
 * панелей, поэтому сюда попадает уже аутентифицированный, но не прошедший
 * второй фактор пользователь, и никакой Livewire-компонент панели не должен
 * рендериться раньше этого гейта.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $this->twoFactor->requiredFor($user)) {
            return redirect()->intended('/admin');
        }

        if ($this->twoFactor->enabledFor($user)) {
            return view('auth.two-factor-challenge');
        }

        $secret = $this->twoFactor->enrollingSecret($request) ?? $this->twoFactor->beginEnrollment($request);
        $uri = Totp::provisioningUri($secret, (string) $user->email, (string) config('app.name'));

        return view('auth.two-factor-setup', [
            'groups' => implode(' ', str_split($secret, 4)),
            'uri' => $uri,
            'qr' => $this->qrSvg($uri),
            'roleLabel' => Roles::all()[$user->role] ?? (string) $user->role,
        ]);
    }

    public function verify(Request $request): View|RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ], [
            'code.required' => 'Введите код из приложения.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $code = (string) $request->input('code');

        if (! $this->twoFactor->enabledFor($user)) {
            $codes = $this->twoFactor->confirmEnrollment($request, $user, $code);
            if ($codes === null) {
                return back()->withErrors([
                    'code' => 'Код не подошёл. Проверьте, что на телефоне верное время, и попробуйте снова.',
                ]);
            }

            // Подтверждение кодом = доказанный второй фактор: сессия проходит
            // гейт. Резервные коды показываем один раз на следующем экране.
            $this->twoFactor->markPassed($request, $user);

            return view('auth.two-factor-recovery-codes', ['codes' => $codes]);
        }

        if (! $this->twoFactor->verify($user, $code)) {
            return back()->withErrors(['code' => 'Неверный код. Можно ввести резервный код вместо кода из приложения.']);
        }

        $this->twoFactor->markPassed($request, $user);

        return redirect()->intended('/admin');
    }

    /** Выход со страницы 2FA — без него гейт запирал бы пользователя в себе. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /** QR рисуем только если пакет есть в vendor (транзитивная зависимость MadelineProto). */
    private function qrSvg(string $uri): ?string
    {
        try {
            $renderer = new ImageRenderer(
                new RendererStyle(220, 1),
                new SvgImageBackEnd,
            );

            return (new Writer($renderer))->writeString($uri);
        } catch (\Throwable) {
            return null;
        }
    }
}
