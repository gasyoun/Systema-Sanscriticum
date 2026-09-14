{{-- H4463: welcome-тур кабинета студента — Teachbase-подобная модалка-обзор.
     Флаг features.cabinet_tour (default ON) + молчание в режиме «войти как»
     (Impersonation, H1947: превью не подделывает активность целевого юзера).
     Показ один раз на браузер (localStorage cabinet_tour_v1); кнопки повторно
     открывают тур событием open-cabinet-tour ($dispatch из дашбордов).
     Телеметрия — только клики существующим контрактом data-track-* (частично
     student.partials.telemetry): impression-атрибут НЕ ставим — скрытые x-show
     узлы телеметрия посчитала бы как показ у всех (см. querySelectorAll на load). --}}
@if (config('features.cabinet_tour') && ! \App\Support\Impersonation::isActive())
@php
    // Состав слайдов зависит от включённых флагов: перечисляем только то,
    // что студент реально видит в меню (иначе слайд обещает скрытый раздел).
    $tourHybrid = (bool) config('features.cabinet_hybrid');
    $tourSrsOn = (bool) config('srs.enabled');
    $tourDrillsOn = (bool) config('features.games_skill_drills');
    $tourMarketing = \App\Models\MarketingSetting::cached();
    $tourBotsOn = (bool) ($tourMarketing?->student_telegram_bot_enabled || $tourMarketing?->student_vk_bot_enabled);

    $tourSteps = [
        [
            'icon' => 'fa-hand-sparkles',
            'title' => 'Добро пожаловать в личный кабинет!',
            'text' => 'Пройдите короткий тур — поможет быстро разобраться, где что находится. В конце — полезные материалы.',
        ],
        [
            'icon' => 'fa-compass',
            'title' => 'Все разделы — в меню слева',
            'text' => $tourHybrid
                ? 'Через это меню управляем обучением: «Сегодня», «Календарь», «Записи», «Прогресс», «Оплата и доступ». Ваши курсы — в блоке «Мои материалы». На телефоне меню открывает кнопка ☰ в шапке.'
                : 'Через это меню управляем обучением: «Кабинет», «Расписание», «Открытые уроки». Ваши курсы — в блоке «Мои материалы». На телефоне меню открывает кнопка ☰ в шапке.',
        ],
        [
            'icon' => 'fa-play-circle',
            'title' => 'Продолжайте с того же места',
            'text' => 'Кнопка «Продолжить» ведёт прямо в следующий урок, а прогресс-бар на карточке курса показывает, сколько уже пройдено.',
        ],
        [
            'icon' => 'fa-calendar-alt',
            'title' => 'Расписание под рукой',
            'text' => $tourHybrid
                ? 'В разделе «Календарь» — живые занятия и записи. Перенос или отмену занятия увидите здесь же.'
                : 'В разделе «Расписание» — живые занятия. Перенос или отмену занятия увидите здесь же.',
        ],
        [
            'icon' => 'fa-credit-card',
            'title' => 'Оплата и доступ',
            'text' => 'Статус оплат и «оплачено до» видно на карточке курса и в разделе «Оплата и доступ». Оплатить следующую часть можно самостоятельно в пару кликов.',
        ],
    ];

    if ($tourBotsOn) {
        $tourSteps[] = [
            'icon' => 'fa-paper-plane',
            'title' => 'Уведомления в Telegram или VK',
            'text' => 'Бот напомнит о занятиях и сроках оплат, ИИ-куратор ответит на вопросы круглосуточно. Подключение — карточками на главной. Без рекламы, отключение в один клик.',
        ];
    }

    if ($tourSrsOn) {
        $tourSteps[] = [
            'icon' => 'fa-layer-group',
            'title' => 'Карточки для запоминания',
            'text' => 'Слова закрепляются интервальными повторениями — разделы «Карточки», «Мои колоды» и «Статистика карточек».'
                . ($tourDrillsOn ? ' Короткие разминки — в «Тренажёрах».' : ''),
        ];
    }

    $tourSteps[] = [
        'icon' => 'fa-life-ring',
        'title' => 'Если нужна помощь',
        'text' => 'Раздел «' . ($tourHybrid ? 'Помощь' : 'Сообщения') . '» — вопрос куратору и поддержка. «Как пользоваться» — подробный гид со скриншотами.',
    ];

    $tourSteps[] = [
        'icon' => 'fa-book-open',
        'title' => 'Полезные материалы',
        'text' => 'Гид кабинета и база знаний — в разделе «Как пользоваться». Проверьте, как освоились, — и начинайте с кнопки «Продолжить»!',
    ];
@endphp
<div x-data="{
        steps: {{ json_encode($tourSteps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }},
        open: false,
        step: 0,
        get total() { return this.steps.length; },
        init() {
            try {
                if (localStorage.getItem('cabinet_tour_v1') !== 'done') {
                    this.open = true;
                }
            } catch (e) {
                this.open = false;
            }
        },
        close(done) {
            this.open = false;
            if (done) {
                try { localStorage.setItem('cabinet_tour_v1', 'done'); } catch (e) {}
            }
        },
        next() {
            if (this.step < this.total - 1) { this.step++; } else { this.close(true); }
        },
        prev() {
            if (this.step > 0) { this.step--; }
        },
        replay() {
            try { localStorage.removeItem('cabinet_tour_v1'); } catch (e) {}
            this.step = 0;
            this.open = true;
        },
     }"
     x-on:open-cabinet-tour.window="replay()"
     x-on:keydown.escape.window="open && close(true)"
     x-show="open" x-cloak
     data-testid="cabinet-tour"
     class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     x-on:click.self="close(true)"
     role="dialog" aria-modal="true" aria-labelledby="cabinet-tour-title">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85dvh] overflow-y-auto custom-scrollbar relative"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">
        <div class="absolute top-0 left-0 w-full h-1.5 bg-brand"></div>

        {{-- Слайд: иконка на градиенте бренда + заголовок + текст --}}
        <div class="px-6 pt-8 pb-2 text-center">
            <div class="mx-auto w-16 h-16 rounded-2xl bg-gradient-to-br from-brand to-orange-500 text-white flex items-center justify-center shadow-[0_8px_24px_rgba(232,92,36,0.35)]">
                <i class="text-2xl" :class="'fas ' + steps[step].icon"></i>
            </div>
            <h3 id="cabinet-tour-title" class="mt-4 text-lg font-extrabold text-gray-900 leading-snug" x-text="steps[step].title"></h3>
            <p class="mt-2 text-sm text-gray-600 leading-relaxed" x-text="steps[step].text"></p>

            {{-- CTA финального слайда: существующий quiz «Проверить кабинет» --}}
            <a href="{{ route('student.cabinet-mastery') }}"
               x-show="step === total - 1"
               class="mt-4 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-bold hover:border-brand hover:text-brand transition-colors shadow-sm">
                <i class="fas fa-clipboard-check"></i> Проверить кабинет
            </a>
        </div>

        {{-- Ноги: счётчик слайдов + навигация. Телеметрия только кликами (impression
             посчитал бы скрытые слайды у всех, см. шапку файла). --}}
        <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50/60">
            <span class="text-xs font-bold text-gray-400 tabular-nums" x-text="(step + 1) + ' / ' + total"></span>
            <div class="flex items-center gap-2">
                <button type="button" x-show="step < total - 1" x-on:click="close(true)"
                        data-track-event="cabinet_tour.skip.click" :data-track-step="step + 1"
                        class="px-3 py-2 rounded-xl text-sm font-bold text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                    Пропустить
                </button>
                <button type="button" x-show="step > 0" x-on:click="prev()" x-cloak
                        class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-bold hover:border-brand hover:text-brand transition-colors">
                    Назад
                </button>
                <button type="button" x-show="step < total - 1" x-on:click="next()"
                        data-track-event="cabinet_tour.next.click" :data-track-step="step + 1"
                        class="px-5 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-extrabold transition-colors shadow-[0_4px_14px_rgba(232,92,36,0.3)]">
                    Далее
                </button>
                <button type="button" x-show="step === total - 1" x-on:click="close(true)" x-cloak
                        data-track-event="cabinet_tour.finish.click" :data-track-step="total"
                        class="px-5 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-extrabold transition-colors shadow-[0_4px_14px_rgba(232,92,36,0.3)]">
                    Готово
                </button>
            </div>
        </div>
    </div>
</div>
@endif
