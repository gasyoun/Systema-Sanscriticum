<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Pages\Auth\AdminRequestPasswordReset;
use App\Filament\Pages\Auth\AdminResetPassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CourseEarningsChart;
use App\Filament\Widgets\RetentionChart;
use App\Filament\Widgets\SalesFunnelChart;
use App\Filament\Widgets\StuckStudentsWidget;
use App\Filament\Widgets\StudentStatsOverview;
use App\Filament\Widgets\UpcomingPromisesWidget;
use App\Http\Middleware\ImpersonationGuard;
use Awcodes\Curator\CuratorPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;

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
                PanelsRenderHook::BODY_END,
                fn () => new HtmlString('
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

                        /* 3. Масштаб таблиц: кнопки x1 / x1.5 / x2 пропорционально ужимают
                           таблицы через CSS zoom (одна переменная --fi-tbl-zoom на :root).
                           Чтобы выпадающее меню действий (⋮) не «съезжало» под zoom, у него
                           ОТКЛЮЧЁН teleport — переопределён вендор-шаблон
                           resources/views/vendor/filament-actions/components/group.blade.php,
                           так плашка рендерится ВНУТРИ масштабируемой таблицы и позиционируется
                           в той же (zoom) системе координат, что и кнопка-триггер. */
                        .fi-ta-content { overflow-x: auto; zoom: var(--fi-tbl-zoom, 1); }
                        /* Плашка зума стоит НАД таблицей: absolute по координатам .fi-ta,
                           скроллится вместе со страницей. Остаётся в body (вне DOM Livewire),
                           поэтому морфинг и модалки не страдают. */
                        .tbl-zoom {
                            position: absolute; z-index: 20;
                            display: flex; align-items: center; gap: 4px;
                            padding: 4px 8px; border-radius: 9px;
                            background: #fff; border: 1px solid rgba(0,0,0,.10);
                            box-shadow: 0 2px 8px rgba(0,0,0,.08);
                        }
                        .dark .tbl-zoom {
                            background: rgba(31,41,55,.95); border-color: rgba(255,255,255,.12);
                            box-shadow: 0 4px 14px rgba(0,0,0,.35);
                        }
                        .tbl-zoom-label { font-size: 11px; opacity: .55; margin-right: 2px; }
                        .tbl-zoom-btn {
                            font-size: 12px; line-height: 1; font-weight: 600;
                            padding: 4px 9px; border-radius: 6px; cursor: pointer;
                            border: 1px solid rgba(0,0,0,.15);
                            background: transparent; color: #374151;
                        }
                        .dark .tbl-zoom-btn { border-color: rgba(255,255,255,.2); color: #e5e7eb; }
                        .tbl-zoom-btn:hover { background: rgba(0,0,0,.05); }
                        .dark .tbl-zoom-btn:hover { background: rgba(255,255,255,.10); }
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
                        // Масштаб таблиц: x1 / x1.5 / x2 через CSS-переменную --fi-tbl-zoom на :root.
                        // Контрол — один блок в body (вне корней Livewire). DOM таблиц не трогаем,
                        // поэтому Livewire-морфинг и модалки не страдают. Позиционируем его НАД
                        // таблицей по координатам .fi-ta.
                        document.addEventListener("livewire:initialized", () => {
                            const KEY = "fiTableZoom";
                            // label — во сколько раз УМЕНЬШАЕМ; zoom — фактический коэффициент.
                            const LEVELS = [
                                { label: "x1",   zoom: 1 },
                                { label: "x1.5", zoom: 1 / 1.5 },
                                { label: "x2",   zoom: 0.5 },
                            ];
                            const current = () => parseFloat(localStorage.getItem(KEY)) || 1;

                            let bar = document.querySelector(".tbl-zoom");

                            // Ставим плашку прямо над первой таблицей страницы (абсолютные
                            // координаты документа → скроллится вместе с контентом).
                            const place = () => {
                                if (!bar) return;
                                const ta = document.querySelector(".fi-ta");
                                if (!ta) { bar.style.display = "none"; return; }
                                bar.style.display = "flex";
                                const r = ta.getBoundingClientRect();
                                bar.style.left = (r.left + window.scrollX) + "px";
                                bar.style.top = (r.top + window.scrollY - bar.offsetHeight - 8) + "px";
                            };

                            const apply = () => {
                                const z = current();
                                document.documentElement.style.setProperty("--fi-tbl-zoom", z);
                                document.querySelectorAll(".tbl-zoom-btn").forEach((b) => {
                                    b.classList.toggle("tbl-zoom-active", Math.abs(parseFloat(b.dataset.zoom) - z) < 0.001);
                                });
                                place();
                            };

                            // Вставляем один раз. Панель без SPA — навигация перезагружает
                            // страницу, так что проверки на каждом переходе достаточно.
                            if (document.querySelector(".fi-ta") && !bar) {
                                bar = document.createElement("div");
                                bar.className = "tbl-zoom";
                                bar.innerHTML = "<span class=\"tbl-zoom-label\">Масштаб таблиц:</span>" +
                                    LEVELS.map((l) => "<button type=\"button\" class=\"tbl-zoom-btn\" data-zoom=\"" + l.zoom + "\">" + l.label + "</button>").join("");
                                bar.querySelectorAll(".tbl-zoom-btn").forEach((b) => {
                                    b.addEventListener("click", () => {
                                        localStorage.setItem(KEY, b.dataset.zoom);
                                        apply();
                                    });
                                });
                                document.body.appendChild(bar);
                            }

                            apply();
                            requestAnimationFrame(place); // дождаться раскладки таблицы
                            window.addEventListener("resize", place);
                            // Фильтр/пагинация перерисовали таблицу — переустановить позицию,
                            // когда обновления Livewire утихли.
                            let placeTimer;
                            Livewire.hook("morph.updated", () => {
                                clearTimeout(placeTimer);
                                placeTimer = setTimeout(place, 120);
                            });
                        });
                    </script>
                ')
            )
            // --- КОНЕЦ ---
            ->brandName('Система Санскритикум')
            ->login(AdminLogin::class)
            // Reuses PasswordResetController (/forgot-password), not a second mailer.
            ->passwordReset(
                AdminRequestPasswordReset::class,
                AdminResetPassword::class,
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->plugins([                        // <--- 2. ПОДКЛЮЧИЛИ САМ ПЛАГИН (Оставили только здесь)
                // Прячем пункт «Media» из меню для преподавателей (роут добивает MediaPolicy).
                CuratorPlugin::make()
                    ->registerNavigation(fn (): bool => auth()->user()?->isTeacher() !== true),

                // Календарный режим расписания (Google-Calendar-like): месяц/неделя/день,
                // drag&drop и создание кликом. Редактирование — через модалку формы Schedule.
                FilamentFullCalendarPlugin::make()
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
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
                StudentStatsOverview::class,
                StuckStudentsWidget::class,
                UpcomingPromisesWidget::class,
                CourseEarningsChart::class,
                SalesFunnelChart::class,
                RetentionChart::class,
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
                // H1947: у панели свой стек middleware — группу `web` она не
                // включает, поэтому режим просмотра за пользователем гейтится
                // здесь отдельно (иначе под куратором не было бы ни плашки, ни
                // fail-closed при снятом флаге).
                ImpersonationGuard::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
