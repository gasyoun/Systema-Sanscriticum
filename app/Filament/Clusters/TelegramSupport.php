<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class TelegramSupport extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Telegram support';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 65; // рядом со «Студентами» (UserResource sort 50)

    protected static ?string $slug = 'telegram-support';

    // Тот же guard, что и у детей — чтобы пустой пункт не светился преподавателям
    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }
}
