<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Отдельная панель для роли «Редактор лекций».
 *
 * Доступ: пользователи с is_admin=true ИЛИ is_lecture_editor=true.
 * Содержит только LectureDraftResource — никаких студентов, платежей, курсов.
 */
class LectureEditorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('editor')
            ->path('editor')
            ->login()
            ->brandName('Конструктор лекций')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(
                in: app_path('Filament/Editor/Resources'),
                for: 'App\\Filament\\Editor\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Editor/Pages'),
                for: 'App\\Filament\\Editor\\Pages',
            )
            ->pages([
                Pages\Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
