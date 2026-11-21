{{-- ИИ-помощник занятия (features.lesson_ai) — вкладка «ИИ» боковой колонки.
     Логика — public/js/lesson-ai.js; компонент вложен в x-data .lesson-layout
     (seekTo, activeTab). Ответы приходят уже отрендеренным безопасным HTML
     (LessonAiService::render), таймкоды — кнопки .lesson-ai-ts.
     Визуально — те же карточки, что «Упражнения» / Learn Your Way на уроке. --}}
<style>
    /* Фразы стенограммы — кнопки; без этого их текст не выделить для «Объяснить». */
    .lesson-ai-selectable button { user-select: text; -webkit-user-select: text; }
    .lesson-ai-result { font-size: 14px; line-height: 1.65; color: #374151; }
    .lesson-ai-result h1, .lesson-ai-result h2, .lesson-ai-result h3 { font-weight: 800; color: #111827; margin: 1.1rem 0 .45rem; font-size: 14px; }
    .lesson-ai-result h1:first-child, .lesson-ai-result h2:first-child, .lesson-ai-result h3:first-child { margin-top: 0; }
    .lesson-ai-result p { margin: .4rem 0; }
    .lesson-ai-result ul, .lesson-ai-result ol { margin: .3rem 0 .7rem; padding-left: 1.15rem; }
    .lesson-ai-result ul { list-style: disc; }
    .lesson-ai-result ol { list-style: decimal; }
    .lesson-ai-result li { margin: .3rem 0; }
    .lesson-ai-result li::marker { color: #e85c24; }
    .lesson-ai-result strong { font-weight: 800; color: #111827; }
    .lesson-ai-ts { display: inline-flex; align-items: center; gap: 4px; padding: 0 6px; margin: 0 2px; border-radius: 6px;
        background: #fff; border: 1px solid #e5e7eb; color: #6b7280;
        font: 800 11px/1.7 ui-monospace, SFMono-Regular, Menlo, monospace; vertical-align: 1px; cursor: pointer; transition: all .15s; }
    .lesson-ai-ts:hover { background: #e85c24; border-color: #e85c24; color: #fff; }
    .lesson-ai-ts i { font-size: 7px; }
    .lesson-ai-dots i { display: inline-block; width: 8px; height: 8px; margin: 0 3px; border-radius: 50%; background: #e85c24; animation: lessonAiDot 1.2s infinite ease-in-out both; }
    .lesson-ai-dots i:nth-child(2) { animation-delay: .15s; }
    .lesson-ai-dots i:nth-child(3) { animation-delay: .3s; }
    @keyframes lessonAiDot { 0%, 80%, 100% { transform: scale(.5); opacity: .35; } 40% { transform: scale(1); opacity: 1; } }
</style>

<div x-show="activeTab === 'ai'" x-cloak
     data-lesson-ai-panel
     data-testid="lesson-ai-panel"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     class="bg-white rounded-3xl shadow-sm border border-gray-100 flex flex-col flex-1 min-h-[500px] overflow-hidden font-nunito">

    {{-- Шапка — как «Навигация по видео» у стенограммы --}}
    <div class="bg-gray-50 px-4 py-3.5 border-b border-gray-100 shrink-0 flex items-center justify-between gap-2 min-h-[56px]">
        <template x-if="state === 'idle'">
            <p class="text-[11px] text-gray-500 font-bold uppercase tracking-wider">ИИ-помощник занятия</p>
        </template>
        <template x-if="state !== 'idle'">
            <button type="button" @click="cancel()"
                    class="inline-flex items-center gap-2 text-[13px] font-extrabold text-gray-700 hover:text-brand transition-colors">
                <span class="w-8 h-8 rounded-lg bg-white border border-gray-200 flex items-center justify-center text-gray-400">
                    <i class="fas fa-arrow-left text-xs"></i>
                </span>
                <span x-text="title"></span>
            </button>
        </template>
    </div>

    {{-- Тело --}}
    <div x-ref="aiResult" class="flex-1 overflow-y-auto custom-scrollbar">

        {{-- Меню --}}
        <div x-show="state === 'idle'" class="p-4 flex flex-col gap-2.5">
            <template x-if="hasTranscript">
                <button type="button" @click="summary()" data-testid="lesson-ai-summary"
                        class="group w-full flex items-center gap-3 p-3 rounded-2xl border border-gray-100 bg-white hover:border-brand/30 hover:shadow-md transition-all text-left">
                    <span class="w-10 h-10 rounded-xl bg-orange-50 text-brand flex items-center justify-center shrink-0"><i class="fas fa-list-ul"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-extrabold text-gray-900">Пересказ занятия</span>
                        <span class="block text-xs text-gray-500">Кратко, оглавление с таймкодами, главные мысли</span>
                    </span>
                    <i class="fas fa-chevron-right text-xs text-gray-300 group-hover:text-brand transition-colors"></i>
                </button>
            </template>

            <button type="button" @click="quiz()" data-testid="lesson-ai-quiz"
                    class="group w-full flex items-center gap-3 p-3 rounded-2xl border border-gray-100 bg-white hover:border-brand/30 hover:shadow-md transition-all text-left">
                <span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0"><i class="fas fa-clipboard-question"></i></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-gray-900">Вопросы для самопроверки</span>
                    <span class="block text-xs text-gray-500">Проверьте себя — ответы спрятаны</span>
                </span>
                <i class="fas fa-chevron-right text-xs text-gray-300 group-hover:text-brand transition-colors"></i>
            </button>

            <button type="button" @click="outline()" data-testid="lesson-ai-outline"
                    class="group w-full flex items-center gap-3 p-3 rounded-2xl border border-gray-100 bg-white hover:border-brand/30 hover:shadow-md transition-all text-left">
                <span class="w-10 h-10 rounded-xl bg-yellow-50 text-yellow-600 flex items-center justify-center shrink-0"><i class="fas fa-pen-to-square"></i></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-gray-900">Конспект из заметок</span>
                    <span class="block text-xs text-gray-500">Черновые заметки → аккуратный конспект</span>
                </span>
                <i class="fas fa-chevron-right text-xs text-gray-300 group-hover:text-brand transition-colors"></i>
            </button>

            <button type="button" @click="explain()" data-testid="lesson-ai-explain"
                    class="group w-full flex items-center gap-3 p-3 rounded-2xl border border-gray-100 bg-white hover:border-brand/30 hover:shadow-md transition-all text-left">
                <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><i class="fas fa-lightbulb"></i></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-gray-900">Объяснить выделенное</span>
                    <span class="block text-xs text-gray-500 truncate"
                          x-text="selection ? '«' + selection + '»' : 'Выделите фразу в тексте, стенограмме или заметках'"></span>
                </span>
                <i class="fas fa-chevron-right text-xs text-gray-300 group-hover:text-brand transition-colors"></i>
            </button>

            <template x-if="hasTranscript">
                <form @submit.prevent="ask()" class="mt-2" data-testid="lesson-ai-ask-form">
                    <p class="text-[11px] text-gray-500 font-bold uppercase tracking-wider mb-2 px-1">Вопрос по занятию</p>
                    <div class="relative">
                        <input type="text" x-model="question" maxlength="500" placeholder="Например: что такое висарга?"
                               class="w-full pl-4 pr-12 py-3 bg-white border border-gray-200 rounded-2xl text-sm font-semibold focus:ring-2 focus:ring-brand outline-none transition-all placeholder-gray-400 shadow-inner">
                        <button type="submit" title="Спросить"
                                class="absolute right-1.5 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl bg-brand hover:bg-brand-hover text-white flex items-center justify-center transition-colors disabled:opacity-40"
                                :disabled="question.trim().length < 5">
                            <i class="fas fa-paper-plane text-xs"></i>
                        </button>
                    </div>
                </form>
            </template>

            <div class="mt-3 flex items-start gap-3 rounded-2xl bg-gray-50 p-3">
                <i class="fas fa-lock text-gray-300 text-xs mt-1 shrink-0"></i>
                <p class="text-[11px] text-gray-500 leading-relaxed">
                    ИИ работает на сервере школы — текст занятия и ваши заметки никуда не передаются.
                    Это подсказка, а не слово преподавателя: сверяйтесь с записью — нажмите на таймкод.
                </p>
            </div>
        </div>

        {{-- Загрузка --}}
        <div x-show="state === 'loading'" class="flex flex-col items-center justify-center text-center gap-4 px-6 py-16">
            <span class="lesson-ai-dots"><i></i><i></i><i></i></span>
            <p class="text-sm text-gray-600 font-semibold max-w-[260px] leading-relaxed" x-text="loadingText"></p>
            <button type="button" @click="cancel()" class="text-xs font-bold text-gray-400 hover:text-gray-600 underline underline-offset-2">Отмена</button>
        </div>

        {{-- Ошибка --}}
        <div x-show="state === 'error'" class="p-4">
            <div class="flex items-start gap-3 rounded-2xl bg-red-50 border border-red-100 p-4">
                <i class="fas fa-circle-exclamation text-red-400 mt-0.5 shrink-0"></i>
                <p class="text-[13px] text-red-800 leading-relaxed" x-text="errorText" data-testid="lesson-ai-error"></p>
            </div>
            <div class="mt-3 flex gap-2">
                <button type="button" @click="retry()" class="flex-1 py-3 rounded-2xl bg-brand hover:bg-brand-hover text-white text-sm font-extrabold transition-colors">Повторить</button>
                <button type="button" @click="cancel()" class="flex-1 py-3 rounded-2xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-extrabold transition-colors">К списку</button>
            </div>
        </div>

        {{-- Результат --}}
        <div x-show="state === 'done'" @click="onResultClick($event)" class="p-4" data-testid="lesson-ai-result">
            <template x-if="task === 'explain' && selection">
                <blockquote class="border-l-4 border-brand/30 bg-orange-50/40 rounded-r-xl px-3 py-2 mb-4 text-[13px] text-gray-600 italic"
                            x-text="selection.length > 300 ? selection.slice(0, 300) + '…' : selection"></blockquote>
            </template>

            <template x-if="task === 'quiz' && questions.length">
                <ol class="flex flex-col gap-2.5">
                    <template x-for="(q, i) in questions" :key="i">
                        <li class="rounded-2xl border border-gray-100 bg-white p-3.5">
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600 text-[11px] font-black flex items-center justify-center shrink-0" x-text="i + 1"></span>
                                <p class="text-[13px] font-extrabold text-gray-900 leading-snug pt-0.5" x-text="q.q"></p>
                            </div>
                            <button type="button" @click="toggleAnswer(i)"
                                    class="mt-2 ml-9 text-xs font-bold text-indigo-600 hover:text-indigo-800"
                                    x-text="opened[i] ? 'Скрыть ответ' : 'Показать ответ'"></button>
                            <div x-show="opened[i]" class="lesson-ai-result mt-2 ml-9" x-html="q.a_html"></div>
                        </li>
                    </template>
                </ol>
            </template>

            <template x-if="task !== 'quiz'">
                <div class="lesson-ai-result" x-html="html"></div>
            </template>

            <p x-show="cached" class="mt-4 text-[11px] text-gray-400">Пересказ подготовлен заранее для всех слушателей занятия.</p>
        </div>
    </div>

    {{-- Действия с результатом --}}
    <div x-show="state === 'done'" class="shrink-0 border-t border-gray-100 p-3 bg-gray-50/70">
        <div class="flex gap-2">
            <template x-if="task === 'outline'">
                <button type="button" @click="replaceNotes()"
                        class="flex-1 py-3 rounded-2xl bg-yellow-400 hover:bg-yellow-500 text-yellow-900 text-[13px] font-extrabold transition-colors">
                    Заменить заметки
                </button>
            </template>
            <button type="button" @click="addToNotes()"
                    class="flex-1 py-3 rounded-2xl text-[13px] font-extrabold transition-colors"
                    :class="task === 'outline' ? 'bg-white border border-gray-200 hover:bg-gray-100 text-gray-700' : 'bg-yellow-400 hover:bg-yellow-500 text-yellow-900'">
                <i class="far fa-edit mr-1"></i> В заметки
            </button>
            <button type="button" @click="copy()" title="Копировать"
                    class="w-12 shrink-0 rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 text-gray-500 transition-colors">
                <i class="fas fa-copy"></i>
            </button>
            <template x-if="task === 'quiz' || task === 'outline'">
                <button type="button" @click="retry()" title="Ещё вариант"
                        class="w-12 shrink-0 rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 text-gray-500 transition-colors">
                    <i class="fas fa-rotate-right"></i>
                </button>
            </template>
        </div>
        <div x-show="flash || notesBefore !== null" class="mt-2 flex items-center justify-between gap-3 px-1">
            <p x-text="flash" class="text-[11px] font-semibold text-gray-500"></p>
            <button type="button" x-show="notesBefore !== null" @click="undoNotes()"
                    class="text-[11px] font-bold text-gray-700 underline underline-offset-2 hover:text-brand shrink-0">Вернуть прежние</button>
        </div>
    </div>
</div>

{{-- Плавающая кнопка у выделенного текста --}}
<button type="button" x-show="pick.show" x-cloak data-lesson-ai-pick
        @mousedown.prevent @click="explainPicked()"
        :style="'position:fixed;left:' + pick.x + 'px;top:' + pick.y + 'px;z-index:60'"
        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-[#19191C] hover:bg-brand text-white text-xs font-extrabold shadow-xl transition-colors">
    <i class="fas fa-wand-magic-sparkles text-[11px]"></i> Объяснить
</button>
