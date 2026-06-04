<?php

namespace App\Policies;

use App\Models\User;
use Awcodes\Curator\Models\Media;

class MediaPolicy
{
    // Преподавателям доступ к медиатеке Curator закрыт (роут /admin/media → 403),
    // пункт меню скрыт через CuratorPlugin::shouldRegisterNavigation.
    public function viewAny(User $user): bool
    {
        return ! $user->isTeacher();
    }

    public function view(User $user, Media $media): bool
    {
        return ! $user->isTeacher();
    }

    public function create(User $user): bool
    {
        return ! $user->isTeacher();
    }

    public function update(User $user, Media $media): bool
    {
        return ! $user->isTeacher();
    }

    public function delete(User $user, Media $media): bool
    {
        return ! $user->isTeacher();
    }
}
