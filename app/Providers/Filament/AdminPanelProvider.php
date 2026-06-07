<?php

namespace App\Providers\Filament;

use Awcodes\Curator\CuratorPlugin; // <--- 1. ДОБАВИЛИ ИМПОРТ ПЛАГИНА
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Левое меню — слайдер «открыл/закрыл»: на десктопе сворачивается
            // в узкую полосу с кликабельными иконками (подписи всплывают при наведении),
            // состояние запоминается в cookie.
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make()->label('Обучение'),
                NavigationGroup::make()->label('Пользователи'),
                NavigationGroup::make()->label('Продажи'),
                NavigationGroup::make()->label('Маркетинг'),
                NavigationGroup::make()->label('Допматериалы'),
            ])
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

                        /* 3. Масштаб блока таблицы: кнопки x1 / x1.5 / x2 ужимают саму таблицу,
                           чтобы широкие таблицы целиком влезали на экран. Уровень общий для всех
                           таблиц и запоминается в localStorage. */
                        .fi-ta-content { overflow-x: auto; }
                        .tbl-zoom {
                            display: flex; align-items: center; gap: 4px;
                            padding: 6px 12px;
                        }
                        .tbl-zoom-label { font-size: 12px; opacity: .6; margin-right: 2px; }
                        .tbl-zoom-btn {
                            font-size: 12px; line-height: 1; font-weight: 600;
                            padding: 4px 9px; border-radius: 6px; cursor: pointer;
                            border: 1px solid rgba(120,120,120,.35);
                            background: transparent; color: inherit;
                        }
                        .tbl-zoom-btn:hover { background: rgba(120,120,120,.12); }
                        .tbl-zoom-btn.tbl-zoom-active {
                            background: rgb(245 158 11); border-color: rgb(245 158 11); color: #1c1917;
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

                    <script>
                        // Масштаб блока таблицы: x1 / x1.5 / x2 ужимают саму таблицу через CSS zoom.
                        // Уровень общий для всех таблиц, хранится в localStorage и переживает Livewire-перерисовки.
                        document.addEventListener("livewire:initialized", () => {
                            const KEY = "fiTableZoom";
                            // label — то, во сколько раз УМЕНЬШАЕМ; zoom — фактический коэффициент.
                            const LEVELS = [
                                { label: "x1",   zoom: 1 },
                                { label: "x1.5", zoom: 1 / 1.5 },
                                { label: "x2",   zoom: 0.5 },
                            ];
                            const current = () => parseFloat(localStorage.getItem(KEY)) || 1;

                            const apply = () => {
                                const z = current();
                                document.querySelectorAll(".fi-ta-content").forEach((el) => { el.style.zoom = z; });
                                document.querySelectorAll(".tbl-zoom-btn").forEach((b) => {
                                    b.classList.toggle("tbl-zoom-active", Math.abs(parseFloat(b.dataset.zoom) - z) < 0.001);
                                });
                            };

                            const inject = () => {
                                document.querySelectorAll(".fi-ta").forEach((ta) => {
                                    if (ta.querySelector(":scope > .tbl-zoom")) return;
                                    const bar = document.createElement("div");
                                    bar.className = "tbl-zoom";
                                    bar.innerHTML = "<span class=\"tbl-zoom-label\">Масштаб таблицы:</span>" +
                                        LEVELS.map((l) => "<button type=\"button\" class=\"tbl-zoom-btn\" data-zoom=\"" + l.zoom + "\">" + l.label + "</button>").join("");
                                    bar.querySelectorAll(".tbl-zoom-btn").forEach((b) => {
                                        b.addEventListener("click", () => {
                                            localStorage.setItem(KEY, b.dataset.zoom);
                                            apply();
                                        });
                                    });
                                    ta.prepend(bar);
                                });
                                apply();
                            };

                            inject();
                            // После каждой перерисовки Livewire (фильтры, пагинация, вкладки) — переустановить кнопки и масштаб.
                            Livewire.hook("morph.updated", () => { inject(); });
                        });
                    </script>
                ')
            )
            // --- КОНЕЦ ---
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->plugins([                        // <--- 2. ПОДКЛЮЧИЛИ САМ ПЛАГИН (Оставили только здесь)
                // Прячем пункт «Media» из меню для преподавателей (роут добивает MediaPolicy).
                CuratorPlugin::make()
                    ->registerNavigation(fn (): bool => auth()->user()?->isTeacher() !== true),

                // Календарный режим расписания (Google-Calendar-like): месяц/неделя/день,
                // drag&drop и создание кликом. Редактирование — через модалку формы Schedule.
                \Saade\FilamentFullCalendar\FilamentFullCalendarPlugin::make()
                    ->selectable()   // выделение дат → создание события
                    ->editable()     // перетаскивание/resize событий
                    ->locale('ru')
                    ->config([
                        'firstDay' => 1, // неделя с понедельника
                        'initialView' => 'dayGridMonth',
                        'headerToolbar' => [
                            'left' => 'prev,next today',
                            'center' => 'title',
                            'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
                        ],
                        'slotMinTime' => '06:00:00',
                        'slotMaxTime' => '24:00:00',
                        'nowIndicator' => true,
                    ]),
            ])
            ->databaseNotifications() // Включаем колокольчик
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
                \App\Filament\Widgets\StudentStatsOverview::class,
                \App\Filament\Widgets\UpcomingPromisesWidget::class,
                \App\Filament\Widgets\CourseEarningsChart::class,
                \App\Filament\Widgets\SalesFunnelChart::class,
                \App\Filament\Widgets\RetentionChart::class,
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
