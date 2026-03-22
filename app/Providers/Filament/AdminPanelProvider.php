<?php

namespace App\Providers\Filament;

use Awcodes\Curator\CuratorPlugin; // <--- 1. ДОБАВИЛИ ИМПОРТ ПЛАГИНА

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession; 
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\HtmlString;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(\Awcodes\Curator\CuratorPlugin::make())
            // --- НАЧАЛО: ПОБЕДА НАД КУРАТОРОМ + ОПТИМИЗАЦИЯ СКОРОСТИ ---
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_END,
                fn () => new \Illuminate\Support\HtmlString('
                    <style>
                        /* 1. Починка картинок в самой форме (Основной экран) - ЭТО ОСТАВЛЯЕМ, оно работает */
                        .fi-main img { max-height: 150px !important; width: auto !important; object-fit: contain !important; border-radius: 0.5rem; }
                        
                        /* 2. НОВАЯ, точечная починка Куратора (Медиатеки/Диалога) */
                        
                        /* Сначала вынуждаем сетку использовать Flexbox wrapping (как и раньше) */
                        dialog .grid { 
                            display: flex !important; 
                            flex-wrap: wrap !important; 
                            gap: 15px !important; 
                            align-items: flex-start !important; 
                        }
                        
                        /* ГЛАВНОЕ: Жестко ограничиваем размер *самой плитки* (контейнера картинки) */
                        dialog .grid > * { 
                            max-width: 150px !important; 
                            max-height: 150px !important; 
                            flex: 0 0 150px !important; /* Flex-grow: 0, Flex-shrink: 0, initial-size: 150px */
                            aspect-ratio: 1/1 !important; /* Принудительно делаем плитку квадратной */
                            overflow: hidden !important; 
                            border-radius: 0.5rem; 
                        }
                        
                        /* Делаем так, чтобы *сама картинка* занимала весь свой контейнер без искажений */
                        dialog img { 
                            width: 100% !important; 
                            height: 100% !important; 
                            object-fit: contain !important; /* Keeps full image visible, respects aspect ratio */
                        }
                    </style>
                    
                    <script>
                        document.addEventListener("livewire:initialized", () => {
                            let scrollFixTimer; // Создаем пустой таймер
                            
                            Livewire.hook("morph.updated", () => {
                                // Если Livewire снова дернулся - отменяем прошлый таймер
                                clearTimeout(scrollFixTimer);
                                
                                // Запускаем новый. Код выполнится только когда обновления прекратятся на 150 миллисекунд
                                scrollFixTimer = setTimeout(() => {
                                    if (document.querySelectorAll("dialog[open]").length === 0) {
                                        document.documentElement.style.removeProperty("overflow");
                                        document.documentElement.classList.remove("overflow-hidden");
                                        document.body.style.removeProperty("overflow");
                                        document.body.classList.remove("overflow-hidden");
                                    }
                                }, 150);
                            });
                        });
                    </script>
                ')
            )
            // --- КОНЕЦ ---
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->plugins([                        // <--- 2. ПОДКЛЮЧИЛИ САМ ПЛАГИН
                CuratorPlugin::make(),
            ])
            ->databaseNotifications() // Включаем колокольчик
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
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