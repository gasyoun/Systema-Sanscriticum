<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\MarkdownGuide;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Руководство куратора в панели (H3213, волна 2).
 *
 * Тонкая страница: гейт как у чата поддержки (не преподаватель),
 * текст — константный docs/CURATOR_ADMIN_GUIDE_RU.md через MarkdownGuide.
 * Существующие canViewAny не трогаем.
 */
class CuratorGuide extends Page
{
    public const SOURCE = 'docs/CURATOR_ADMIN_GUIDE_RU.md';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Руководство';

    protected static ?string $title = 'Руководство куратора';

    protected static ?string $slug = 'curator-guide';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.curator-guide';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->isTeacher() !== true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function sourcePath(): string
    {
        return base_path(self::SOURCE);
    }

    public function guideHtml(): ?string
    {
        return MarkdownGuide::html(self::SOURCE);
    }
}
