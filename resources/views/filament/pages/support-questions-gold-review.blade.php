<x-filament-panels::page>
    <style>
        .sgr-text-card { white-space: pre-wrap; word-break: break-word; }
        .sgr-choice-grid { display: grid; grid-template-columns: 1fr; gap: 0.5rem; }
        @media (min-width: 768px) { .sgr-choice-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .sgr-pos-chip { display: inline-flex; align-items: center; justify-content: center;
            min-width: 2.25rem; height: 2.25rem; border-radius: 0.5rem; border: 1px solid;
            padding: 0 0.35rem; font-size: 0.8rem; cursor: pointer; }
        .sgr-verdict pre { max-height: 22rem; overflow: auto; }
    </style>

    <div class="space-y-4">
        @php($samples = $this->samples)

        @if (count($samples) === 0)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-lg font-semibold text-gray-950 dark:text-white">Выборок ещё нет</div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Заморозьте стратифицированную выборку ниже (как у H5709: окно всего бэкфилла, 100 сообщений) —
                    затем авторизованный ревьюер размечает её вслепую, по одному сообщению.
                </div>
            </div>
        @endif

        @if (count($samples) > 0)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Выборки</div>
                <div class="sqw-table-wrap mt-2 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="py-1 pr-3">Окно</th>
                                <th class="py-1 pr-3">Версия</th>
                                <th class="py-1 pr-3">Прогресс</th>
                                <th class="py-1 pr-3">Статус</th>
                                <th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($samples as $s)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-3 font-mono text-xs">{{ $s['window'] }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs">
                                        {{ $s['version'] }}
                                        @if ($s['stale'])
                                            <span class="ml-1 font-sans font-medium text-amber-600 dark:text-amber-400">устарела</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 pr-3">{{ $s['progress']['labeled'] }}/{{ $s['progress']['total'] }}</td>
                                    <td class="py-1.5 pr-3">
                                        @if ($s['status'] === \App\Models\SupportQuestionReviewSample::STATUS_COMPLETED)
                                            <span class="font-medium text-emerald-600 dark:text-emerald-400">завершена</span>
                                        @else
                                            <span>открыта</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 text-right">
                                        <button type="button" wire:click="selectSample({{ $s['id'] }})"
                                                class="rounded-lg border border-gray-200 px-2 py-1 text-xs dark:border-white/10">
                                            открыть
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @php($active = $this->activeSample)

        @if ($active !== null)
            @php($progress = $this->progress)
            @php($item = $this->currentItem)
            @php($stale = $active->classifier_version !== \App\Services\SupportQuestions\QuestionMessageClassifier::VERSION)

            <div class="rounded-xl border {{ $progress['complete'] ? 'border-emerald-300 bg-emerald-50 dark:bg-emerald-950/30' : 'border-gray-200 bg-white' }} p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="text-lg font-bold text-gray-950 dark:text-white">
                        Размечено {{ $progress['labeled'] }} из {{ $progress['total'] }}
                        @if ($progress['remaining'] > 0)
                            <span class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-400">осталось {{ $progress['remaining'] }}</span>
                        @endif
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        окно <code>{{ $active->windowLabel() }}</code> · классификатор <code>{{ $active->classifier_version }}</code>
                    </div>
                </div>

                @if ($progress['total'] > 0 && $progress['total'] < \App\Services\SupportQuestions\GoldReviewService::REQUIRED_FOR_GATE)
                    <div class="mt-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                        ⚠️ В выборке меньше {{ \App\Services\SupportQuestions\GoldReviewService::REQUIRED_FOR_GATE }} сообщений — недельный гейт по ней будет inconclusive.
                    </div>
                @endif

                @if ($stale)
                    <div class="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-medium text-amber-800 dark:border-amber-500/40 dark:bg-amber-950/30 dark:text-amber-300">
                        Выборка заморожена под версию {{ $active->classifier_version }}, текущая —
                        {{ \App\Services\SupportQuestions\QuestionMessageClassifier::VERSION }}. Досылать метки нельзя:
                        заморозьте новую выборку, иначе версии смешаются.
                    </div>
                @endif

                @if ($item !== null)
                    <div class="mt-3 flex flex-wrap items-center gap-1">
                        @foreach (range(1, $progress['total']) as $p)
                            <button type="button" wire:click="goTo({{ $p }})"
                                    class="sgr-pos-chip {{ $p === $this->at ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-200 text-gray-600 dark:border-white/10 dark:text-gray-400' }}">
                                {{ $p }}
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-gray-800/60">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Сообщение {{ $this->at }} / {{ $progress['total'] }} · {{ $item->population }}
                            </div>
                            @if ($item->label !== null)
                                <div class="text-xs font-medium text-emerald-700 dark:text-emerald-400">размечено: {{ $item->label->gold_label }}</div>
                            @else
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">не размечено</div>
                            @endif
                        </div>

                        <div class="sgr-text-card mt-2 text-base text-gray-950 dark:text-gray-100">{{ $item->messageText() }}</div>

                        @if ($item->label !== null)
                            {{-- Слепая разметка: предсказание раскрывается только ПОСЛЕ записи gold-метки. --}}
                            <div data-prediction="{{ $item->predicted_primary }}"
                                 class="mt-3 rounded-lg border {{ $item->predicted_primary === $item->label->gold_label ? 'border-emerald-300 bg-emerald-50 dark:bg-emerald-950/30' : 'border-amber-300 bg-amber-50 dark:bg-amber-950/30' }} p-3 text-sm">
                                Модель: <code>{{ $item->predicted_primary }}</code> ·
                                @if ($item->predicted_primary === $item->label->gold_label)
                                    совпадение
                                @else
                                    расхождение
                                @endif
                            </div>
                        @endif
                    </div>

                    @unless ($stale || $active->status !== \App\Models\SupportQuestionReviewSample::STATUS_OPEN)
                        <div class="mt-4">
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">Gold-метка (выберите одну)</div>
                            <div class="sgr-choice-grid mt-2">
                                @foreach (\App\Services\SupportQuestions\QuestionMessageClassifier::CATEGORY_TITLES as $code => $title)
                                    <button type="button" wire:click="saveLabel('{{ $code }}')"
                                            wire:key="choice-{{ $code }}-{{ $item->id }}"
                                            class="rounded-lg border {{ $item->label?->gold_label === $code ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:bg-gray-800' }} px-3 py-2 text-left text-sm">
                                        <span class="font-mono font-bold">{{ $code }}</span> — {{ $title }}
                                    </button>
                                @endforeach
                                <button type="button" wire:click="saveLabel('unclassified')"
                                        wire:key="choice-unclassified-{{ $item->id }}"
                                        class="rounded-lg border {{ $item->label?->gold_label === 'unclassified' ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:bg-gray-800' }} px-3 py-2 text-left text-sm">
                                    <span class="font-mono font-bold">unclassified</span> — вопрос, но тема вне A–I, категорию указать нельзя
                                </button>
                                <button type="button" wire:click="saveLabel('not_question')"
                                        wire:key="choice-not-question-{{ $item->id }}"
                                        class="rounded-lg border {{ $item->label?->gold_label === 'not_question' ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:bg-gray-800' }} px-3 py-2 text-left text-sm">
                                    <span class="font-mono font-bold">not_question</span> — не вопрос (флуд, эмодзи, реакция, служебное)
                                </button>
                                <button type="button" wire:click="saveLabel('other')"
                                        wire:key="choice-other-{{ $item->id }}"
                                        class="rounded-lg border {{ $item->label?->gold_label === 'other' ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:bg-gray-800' }} px-3 py-2 text-left text-sm">
                                    <span class="font-mono font-bold">other</span> — вопрос, но про другое (не подпадает ни под A–I, ни под unclassified)
                                </button>
                            </div>
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Метку можно перезаписать — повторная запись обновляет, а не дублирует. Предсказание модели скрыто до записи метки.
                            </div>
                        </div>
                    @endunless
                @endif

                @if ($progress['complete'])
                    <div class="mt-4 rounded-lg border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-500/40 dark:bg-emerald-950/30">
                        <div class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">
                            Все {{ $progress['total'] }} строк размечены — выгрузите gold-лист и получите вердикт импортёра.
                        </div>
                        <button type="button" wire:click="exportAndVerify()"
                                class="mt-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Экспорт gold-листа + вердикт
                        </button>
                        @if ($this->lastVerdict)
                            <div class="sgr-verdict mt-3">
                                <pre class="rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ $this->lastVerdict }}</pre>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-sm font-semibold text-gray-950 dark:text-white">Заморозить новую выборку</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Окно задают как у CLI-ревью (даты YYYY-MM-DD, конец эксклюзивно); пусто — последняя неделя снапшота.
                Состав фиксируется навсегда; предзаполненные gold-метки не принимаются.
            </div>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <label class="block text-xs text-gray-500 dark:text-gray-400">
                    от
                    <input type="date" wire:model.live.debounce.500ms="freezeFrom"
                           class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-white/10 dark:bg-gray-800">
                </label>
                <label class="block text-xs text-gray-500 dark:text-gray-400">
                    до (эксклюзивно)
                    <input type="date" wire:model.live.debounce.500ms="freezeTo"
                           class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-white/10 dark:bg-gray-800">
                </label>
                <label class="block text-xs text-gray-500 dark:text-gray-400">
                    размер
                    <input type="number" min="1" max="500" wire:model.live.debounce.500ms="freezeSize"
                           class="mt-1 block w-24 rounded-lg border-gray-200 text-sm dark:border-white/10 dark:bg-gray-800">
                </label>
                <button type="button" wire:click="runFreeze()"
                        class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900">
                    Заморозить
                </button>
            </div>
            @if ($errors->any())
                <div class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $errors->first() }}</div>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-300">
            <div class="font-semibold text-gray-950 dark:text-white">Памятка ревьюеру</div>
            <ol class="mt-2 list-decimal space-y-1 pl-5">
                <li>Открывается по одному сообщению; предсказание модели скрыто, пока вы не запишете метку — оценивайте только текст.</li>
                <li>Выберите ОДНУ главную тему (primary). Если затронуто несколько — берите преобладающую.</li>
                <li>Прогресс сохраняется: можно закрыть страницу и вернуться — курсор встанет на первую неразмеченную строку.</li>
                <li>~100 сообщений ≈ 20–40 минут; размечать должен один человек за проход.</li>
                <li>Тексты переписки не покидают эту страницу: копировать их наружу нельзя.</li>
            </ol>
        </div>
    </div>
</x-filament-panels::page>
