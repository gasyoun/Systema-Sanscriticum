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
 * Личность и ДОСТУП берём так:
 *  - подписанная ссылка из бота/напоминаний (есть параметр `u`) — доверяем подписи
 *    (подпись сама доказывает право на редирект);
 *  - иначе авторизованный студент С ДОСТУПОМ к занятию (его группа или общее
 *    занятие без группы) / сотрудник — редиректим и пишем клик;
 *  - аноним по неподписанной ссылке — на вход, БЕЗ редиректа на Zoom.
 *
 * Раньше неподписанный анонимный запрос всё равно редиректил на настоящий
 * Zoom-URL: id занятий — последовательные числа (`whereNumber`), так что любой
 * мог перебором /class/1/join, /class/2/join… посещать платные живые занятия без
 * оплаты и аккаунта (money-core, H071 #6).
 */
class JoinClassController extends Controller
{
    private const SOURCES = ['cabinet', 'telegram', 'vk', 'reminder'];

    public function join(Request $request, Schedule $schedule): RedirectResponse
    {
        // 1) Подписанная ссылка из бота/напоминаний — подпись доказывает право.
        if ($request->hasValidSignature() && $request->filled('u')) {
            $userId = (int) $request->query('u');
            if (User::whereKey($userId)->exists()) {
                ScheduleJoinClick::record(
                    $schedule->id,
                    $userId,
                    $this->normalizeSource((string) $request->query('source', 'reminder'))
                );
            }

            return $this->redirectToClass($schedule);
        }

        // 2) Иначе — только авторизованный пользователь.
        $user = auth()->user();
        if (! $user) {
            return redirect()->guest(route('login'));
        }

        // 3) …и только с доступом к занятию: его группа, общее занятие без группы,
        //    либо сотрудник (не студент). Иначе — 403, без выдачи Zoom-ссылки.
        if (! $this->canAccess($user, $schedule)) {
            abort(403, 'Нет доступа к этому занятию.');
        }

        ScheduleJoinClick::record($schedule->id, $user->id, 'cabinet');

        return $this->redirectToClass($schedule);
    }

    /**
     * Может ли пользователь подключиться к занятию. Зеркалит гейт календаря
     * кабинета (StudentController::calendar): занятие без группы видно всем, иначе
     * только участникам группы. Сотрудники (не студенты) — всегда.
     */
    private function canAccess(User $user, Schedule $schedule): bool
    {
        // Сотрудники (не студенты) — всегда: у студентов роль пустая, у персонала —
        // одна из Roles::* (админ/преподаватель/менеджер/бухгалтер).
        if ($user->isAdminLike() || $user->isTeacher() || $user->isManager() || $user->isAccountant()) {
            return true;
        }

        if (blank($schedule->group_id)) {
            return true;
        }

        return $user->groups()->whereKey($schedule->group_id)->exists();
    }

    private function redirectToClass(Schedule $schedule): RedirectResponse
    {
        return $schedule->link
            ? redirect()->away($schedule->link)
            : redirect()->route('student.dashboard');
    }

    private function normalizeSource(string $source): string
    {
        return in_array($source, self::SOURCES, true) ? $source : 'reminder';
    }
}
