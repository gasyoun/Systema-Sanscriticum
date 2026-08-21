<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\MarkdownGuide;
use App\Support\RoleGate;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Операционная книга бухгалтера в панели (H3214, волна 3).
 *
 * Тонкая страница: гейт finance(), текст — константный
 * docs/ACCOUNTANT_CABINET_GUIDE_RU.md. Кадры — storage, не git.
 * PayoutAttributionGuide не заменяется: живая очередь остаётся там.
 */
class AccountantGuide extends Page
{
    public const SOURCE = 'docs/ACCOUNTANT_CABINET_GUIDE_RU.md';

    public const SHOT_PREFIX = 'screenshots/accountant/';

    public const SHOT_ROUTE_PREFIX = '/staff/accountant-guide-shots/';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Как работать бухгалтеру';

    protected static ?string $title = 'Как работать бухгалтеру';

    protected static ?string $slug = 'accountant-guide';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 44;

    protected static string $view = 'filament.pages.accountant-guide';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    public static function sourcePath(): string
    {
        return base_path(self::SOURCE);
    }

    public function guideHtml(): ?string
    {
        return MarkdownGuide::html(
            self::SOURCE,
            self::SHOT_ROUTE_PREFIX,
            self::SHOT_PREFIX,
        );
    }

    /** Кнопка с рабочих финэкранов — не меняет чужие canAccess. */
    public static function openAction(): Action
    {
        return Action::make('accountantGuide')
            ->label('Как работать бухгалтеру')
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->url(static::getUrl());
    }
}
