<?php

namespace App\Policies;

use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Витринная видимость «Списка ожидания» (H3834): ссылка на /online/zhdun
 * показывается только когда флаг waitlist_voting ON. Для гостей тоже видна —
 * голосовать они смогут после входа (401 → /login, паттерн кабинета H3815).
 */
class CourseWaitlistItemPolicy
{
    public function view(?User $user, CourseWaitlistItem $item): Response
    {
        return config('features.waitlist_voting', false)
            ? Response::allow()
            : Response::deny();
    }
}
