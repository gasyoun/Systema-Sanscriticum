<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\HindiAgentDrillReview;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * H3206 — все агентские упражнения хинди одним списком, только кабинет преподавателя.
 *
 * Студентам эта страница не отдаётся. Не публичный issue.
 */
class HindiAgentDrillsReview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Черновик упражнений хинди';

    protected static ?string $title = 'Черновик упражнений хинди (агент)';

    protected static ?string $slug = 'hindi-agent-drills';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.hindi-agent-drills-review';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return (bool) $user->is_admin
            || (bool) $user->is_lecture_editor
            || $user->teacher_id !== null
            || $user->isTeacher();
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Keep off the teacher-guide census (heading + Dusk screenshot).
        // Entry is the playlist link «Все агентские упражнения одним списком».
        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lessons(): array
    {
        return app(HindiAgentDrillReview::class)->lessons();
    }
}
