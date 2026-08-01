<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Вход и выход из режима просмотра за пользователя (H1947).
 *
 * Старт — GET по ПОДПИСАННОЙ короткоживущей ссылке (её выдаёт действие в
 * UserResource): подпись закрывает CSRF на переходе, а права всё равно
 * перепроверяются здесь, на сервере, а не только видимостью кнопки.
 * Выход — POST с CSRF-токеном из плашки.
 *
 * Флаг выключен → 404 на обоих маршрутах (маршруты регистрируются всегда: так
 * тесты и уже открытая сессия проверяются, не перезагружая роутер).
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, string $mode): RedirectResponse
    {
        abort_unless(Impersonation::enabled(), 404);
        abort_unless(in_array($mode, Impersonation::modes(), true), 404);
        abort_unless(Impersonation::canImpersonate($user, $mode), 403);

        Impersonation::start($user, $mode, $request);

        return $mode === Impersonation::MODE_MANAGER
            ? redirect()->to('/admin')
            : redirect()->route('student.dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        abort_unless(Impersonation::isActive(), 404);

        $restored = Impersonation::stop($request);

        if (! $restored instanceof User) {
            return redirect()->route('login')
                ->with('status', 'Режим просмотра закрыт, но вернуть прежнюю учётную запись не удалось — войдите заново.');
        }

        return redirect()->to('/admin/users')
            ->with('status', 'Вы вышли из режима просмотра и снова работаете от своего имени.');
    }
}
