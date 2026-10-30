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
use App\Http\Middleware\RequireTwoFactor;
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
            // TiptapEditor крадёт фокус у других полей формы — см. view.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.tiptap-focus-fix'),
            )
            // --- НАЧАЛО: ПОБЕДА НАД КУРАТОРОМ + ОПТИМИЗАЦИЯ СКОРОСТИ ---
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => new HtmlString('
                    <style>
                        /* 1. Превья поля CuratorPicker в формах: сохраняем исторический вид
   (максимум 150px, contain) — но ТОЛЬКО внутри компонента пикера.
   Якорь по DOM: обёртка div.curator-media-picker в
   awcodes/filament-curator v3.7.10 (resources/views/components/forms/picker.blade.php).
   Прежнее правило висело на .fi-main img и сжимало ВСЕ картинки админки:
   обложку контент-календаря (пришлось глушить инлайн-!important в
   ContentCalendarSlotResource), превью баннеров уроков, аватары бота.
   Скоуп снят — эти элементы снова рендерятся по своему собственному CSS. */
.curator-media-picker img { max-height: 150px !important; width: auto !important; object-fit: contain !important; border-radius: 0.5rem; }

/* 2. Сетку медиатеки Curator НЕ трогаем. Модалка пикера — это
   div.fi-modal.curator-panel: Filament v3.3.54 рендерит модалки как
   role=dialog-див, а НЕ нативный <dialog>; сетка ul.curator-picker-grid
   уже респонсивная (grid-cols-3 … 2xl:grid-cols-10), плитки li.aspect-square.
   Прежние селекторы dialog .grid / dialog .grid > * / dialog img не совпадали
   ни с одним элементом установленного DOM (нативного <dialog> нет ни в
   Filament, ни в Curator, ни во вьюхах приложения) — удалены как мёртвые,
   чтобы не сработали внезапно при появлении настоящего <dialog>. */

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
                RequireTwoFactor::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
