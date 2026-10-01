<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Ответ на недействительную одноразовую ссылку входа (/login-link, /tg-login).
 *
 * Раньше это был голый 404 «Not Found»: студент, который уже вошёл по ссылке и
 * открыл её ещё раз (встроенный браузер Яндекс-приложения или Telegram часто не
 * держит сессию), думал, что кабинет сломан (7yogik, 29-09-2026).
 *
 * Анти-перебор сохраняется: «не найдена», «протухла», «уже использована» и
 * «чужое назначение» дают ОДИН И ТОТ ЖЕ ответ — по нему нельзя отличить живой
 * токен от выдуманного. Уже вошедший пользователь просто попадает в кабинет.
 */
final class UsedLoginLinkResponse
{
    public const MESSAGE = 'Ссылка для входа уже использована или устарела. Войдите по паролю или попросите куратора прислать новую ссылку.';

    public static function make(): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('student.dashboard');
        }

        return redirect()->route('login')->with('status', self::MESSAGE);
    }
}
