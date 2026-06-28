<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Трекинг-редирект «Подключиться к занятию»: фиксируем, кто переходит по ссылке
 * (надёжная привязка студент → занятие для учёта посещаемости), и редиректим на
 * настоящий Zoom-URL.
 *
 * Личность берём так:
 *  - подписанная ссылка из бота/напоминаний (есть параметр `u`) — доверяем подписи;
 *  - иначе авторизованный пользователь кабинета (source=cabinet);
 *  - аноним по неподписанной ссылке — просто редиректим без записи клика.
 */
class JoinClassController extends Controller
{
    private const SOURCES = ['cabinet', 'telegram', 'vk', 'reminder'];

    public function join(Request $request, Schedule $schedule): RedirectResponse
    {
        $userId = null;
        $source = 'cabinet';

        if ($request->hasValidSignature() && $request->filled('u')) {
            $userId = (int) $request->query('u');
            $source = (string) $request->query('source', 'reminder');
        } elseif (auth()->check()) {
            $userId = (int) auth()->id();
            $source = 'cabinet';
        }

        if ($userId && User::whereKey($userId)->exists()) {
            ScheduleJoinClick::record($schedule->id, $userId, $this->normalizeSource($source));
        }

        return $schedule->link
            ? redirect()->away($schedule->link)
            : redirect()->route('student.dashboard');
    }

    private function normalizeSource(string $source): string
    {
        return in_array($source, self::SOURCES, true) ? $source : 'reminder';
    }
}
